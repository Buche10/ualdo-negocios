<?php

namespace App\Services;

use App\Models\AdminChatMessage;
use App\Models\Business;
use App\Models\User;
use App\Services\AdminTools\AdminConfigTools;
use App\Services\AdminTools\AdminCrossSkillTools;
use App\Services\AdminTools\AdminReadTools;
use App\Services\AdminTools\AdminWriteTools;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Throwable;

class UaldoAdminService
{
    protected const MAX_INPUT_LENGTH = 1000;

    protected const MAX_HISTORY_MESSAGES = 10;

    protected const MAX_STEPS = 5;

    /**
     * Process an admin chat message from a dashboard user.
     */
    public function processMessage(User $user, string $messageText): string
    {
        // Fail closed if user or business is missing
        /** @var Business|null $business */
        $business = $user->business;
        if (! $business) {
            return 'Error: Tu usuario no pertenece a un negocio registrado.';
        }

        // Enforce active BusinessContext
        BusinessContext::set($business);

        // Sanitize and cap input length to prevent token overflow/abuse
        $cleanInput = trim($messageText);
        if (mb_strlen($cleanInput) > self::MAX_INPUT_LENGTH) {
            $cleanInput = mb_substr($cleanInput, 0, self::MAX_INPUT_LENGTH);
        }

        if (empty($cleanInput)) {
            return 'Por favor ingresa una pregunta o instrucción válida.';
        }

        // Persist incoming user message
        AdminChatMessage::create([
            'business_id' => $business->id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $cleanInput,
        ]);

        // Generate AI response with Prism fallback
        try {
            $reply = $this->generateAiResponse($user, $business, $cleanInput);
        } catch (Throwable $e) {
            Log::error("Error en UaldoAdminService para usuario #{$user->id}: ".$e->getMessage(), [
                'exception' => $e,
            ]);
            $reply = 'Lo siento, ocurrió un inconveniente técnico al procesar tu consulta administrativa. Por favor intenta de nuevo en unos momentos.';
        }

        // Persist assistant message response
        if (! empty($reply)) {
            AdminChatMessage::create([
                'business_id' => $business->id,
                'user_id' => $user->id,
                'role' => 'assistant',
                'content' => $reply,
            ]);
        }

        return $reply;
    }

    /**
     * Generate response using Prism LLM with resilient DeepSeek -> OpenAI fallback.
     */
    protected function generateAiResponse(User $user, Business $business, string $cleanInput): string
    {
        $tools = $this->resolveAdminTools($user);
        $systemPrompt = $this->build3LayerSystemPrompt($business, $user);

        // History capped to last N=10 messages
        $history = AdminChatMessage::where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->limit(self::MAX_HISTORY_MESSAGES)
            ->get()
            ->reverse();

        $prismMessages = [];
        foreach ($history as $msg) {
            if ($msg->role === 'user') {
                $prismMessages[] = new UserMessage($msg->content);
            } else {
                $prismMessages[] = new AssistantMessage($msg->content);
            }
        }

        // Attempt 1: DeepSeek
        try {
            return $this->executePrismCall('deepseek', 'deepseek-chat', $systemPrompt, $prismMessages, $tools);
        } catch (Throwable $e) {
            Log::warning('Fallo en proveedor primario DeepSeek para Admin Chat. Fallback a OpenAI: '.$e->getMessage());

            // Attempt 2: OpenAI GPT-4o-mini
            try {
                return $this->executePrismCall('openai', 'gpt-4o-mini', $systemPrompt, $prismMessages, $tools);
            } catch (Throwable $e2) {
                Log::error('Fallo también en proveedor secundario OpenAI en Admin Chat: '.$e2->getMessage());
                throw $e2;
            }
        }
    }

    /**
     * Execute Prism API call with bounded steps.
     *
     * @param  array<int, mixed>  $prismMessages
     * @param  array<int, mixed>  $tools
     */
    protected function executePrismCall(string $provider, string $model, string $systemPrompt, array $prismMessages, array $tools): string
    {
        $prismProvider = match ($provider) {
            'deepseek' => Provider::DeepSeek,
            'openai' => Provider::OpenAI,
            default => Provider::OpenAI,
        };

        $response = Prism::text()
            ->using($prismProvider, $model)
            ->withSystemPrompt($systemPrompt)
            ->withMessages($prismMessages)
            ->withTools($tools)
            ->withMaxSteps(self::MAX_STEPS)
            ->generate();

        return $response->text;
    }

    /**
     * Resolve all registered admin tools for the current user session.
     *
     * @return array<int, mixed>
     */
    public function resolveAdminTools(User $user): array
    {
        $readTools = app(AdminReadTools::class)->getTools($user);
        $writeTools = app(AdminWriteTools::class)->getTools($user);
        $configTools = app(AdminConfigTools::class)->getTools($user);
        $crossSkillTools = app(AdminCrossSkillTools::class)->getTools($user);

        return array_merge($readTools, $writeTools, $configTools, $crossSkillTools);
    }

    /**
     * Build 3-Layer System Prompt:
     * Layer 1: Core Identity
     * Layer 2: Custom Persona (Delimited & Length-capped)
     * Layer 3: Immutable Operational & Security Rules
     */
    public function build3LayerSystemPrompt(?Business $business, User $user): string
    {
        $tz = $business?->timezone ?? config('app.timezone', 'America/Guayaquil');
        $now = Carbon::now($tz)->format('Y-m-d H:i (l)');
        $businessName = $business?->name ?? 'Negocio';
        $hoursStart = $business?->business_hours_start ?? '09:00';
        $hoursEnd = $business?->business_hours_end ?? '18:00';

        // Layer 1: Core Identity
        $layer1 = <<<LAYER1
[CAPA 1: IDENTIDAD BASE]
Eres Ualdo Admin, el co-piloto y asistente inteligente de gestión administrativa para el dueño y equipo del negocio '{$businessName}'.
Tu objetivo es ayudar en la gestión de inventarios, agenda de citas, configuración del negocio y asignación de tareas a colaboradores.
LAYER1;

        // Layer 2: Custom Persona (Delimited & Capped)
        $rawPersona = $business?->settings['ai_persona'] ?? null;
        if (! empty($rawPersona)) {
            // Cap custom text length to 300 chars to avoid token inflation
            $cleanPersona = mb_substr(trim((string) $rawPersona), 0, 300);
            $layer2 = <<<LAYER2
[CAPA 2: PERSONALIDAD DEL NEGOCIO]
Aplica las siguientes preferencias de tono expresadas por el negocio:
"{$cleanPersona}"
LAYER2;
        } else {
            $layer2 = <<<'LAYER2'
[CAPA 2: PERSONALIDAD DEL NEGOCIO]
Utiliza el tono por defecto: Profesional, conciso, proactivo y cortés.
LAYER2;
        }

        // Layer 3: Immutable Operational & Security Rules
        $layer3 = <<<LAYER3
[CAPA 3: REGLAS INMUTABLES DE OPERACIÓN Y SEGURIDAD]
1. CONTEXTO: Fecha actual: {$now}. Zona horaria: {$tz}. Horario comercial: {$hoursStart} a {$hoursEnd}.
2. USUARIO EN CHAT: Nombre: {$user->name}, Rol: {$user->role}.
3. SEGURIDAD Y MULTI-TENANT: Nunca consultes ni reveles información pertenecientes a otros negocios distintas a '{$businessName}'.
4. USO DE HERRAMIENTAS: Para responder sobre inventario, existencias, citas o métricas, USA SIEMPRE las herramientas proveídas. No inventes datos.
5. RESPUESTAS AMBIGUAS: Si el usuario solicita modificar inventario o precios con información incompleta, PIDE aclaraciones en lugar de suponer cantidades o precios.
6. APROBACIÓN REQUERIDA: Las acciones de alto impacto (eliminar items, cambiar precios, alterar configuración) requieren confirmación del usuario. No intentes omitir o forzar confirmaciones.
LAYER3;

        return "{$layer1}\n\n{$layer2}\n\n{$layer3}";
    }
}
