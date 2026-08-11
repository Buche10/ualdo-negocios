<?php

namespace App\Services;

use App\Models\Business;
use App\Models\Contact;
use App\Models\Message;
use App\Skills\SkillProvider;
use App\Skills\SkillRegistry;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Enums\Provider;
use Prism\Prism\Prism;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Throwable;

class UaldoManagerService
{
    /**
     * Resuelve el negocio activo y orquesta la interacción de IA por WhatsApp/Telegram.
     */
    public function processIncomingMessage(
        string $senderPhone,
        string $messageText,
        string $channel = 'whatsapp',
        ?string $waId = null,
        ?string $profileName = null
    ): string {
        $business = BusinessContext::get() ?? Business::first();
        $tz = $business?->timezone ?? config('app.timezone', 'America/Guayaquil');

        // Check idempotency por wa_id
        if (! empty($waId)) {
            if (Message::where('wa_id', $waId)->exists()) {
                Log::warning("Mensaje duplicado omitido por wa_id {$waId}");

                return '';
            }
        }

        // 1. Obtener o crear Contacto / Paciente
        $contact = Contact::firstOrCreate(
            ['phone_number' => $senderPhone],
            ['name' => $profileName ?: 'Paciente']
        );

        // Actualizar el nombre real del paciente si WhatsApp lo provee y aún es genérico
        if ($profileName && in_array($contact->name, ['Paciente', '', null], true)) {
            $contact->update(['name' => $profileName]);
        }

        // 2. Registrar el mensaje entrante del usuario (atómico ante carreras de wa_id)
        try {
            Message::create([
                'contact_id' => $contact->id,
                'role' => 'user',
                'content' => $messageText,
                'wa_id' => $waId,
            ]);
        } catch (QueryException $e) {
            Log::warning("QueryException por wa_id duplicado capturada limpiamente ({$waId})");

            return '';
        }

        // 3. Verificar si el bot está pausado para este paciente (Handoff humano)
        if ($contact->bot_paused_until && Carbon::parse($contact->bot_paused_until, $tz)->isFuture()) {
            Log::info("Bot pausado para paciente {$senderPhone} hasta {$contact->bot_paused_until}. Mensaje no procesado por IA.");

            return '';
        }

        // 4. Intentar generar respuesta con la IA
        try {
            $reply = $this->generateAiResponse($contact, $messageText, $business);
        } catch (Throwable $e) {
            Log::error('Error general en UaldoManagerService: '.$e->getMessage(), [
                'exception' => $e,
            ]);
            $reply = "Lo siento, en este momento tengo un problema técnico para procesar tu solicitud. Un asesor de {$business?->name} te responderá pronto.";
        }

        // 5. Guardar la respuesta del asistente si no está vacía
        if (! empty($reply)) {
            Message::create([
                'contact_id' => $contact->id,
                'role' => 'assistant',
                'content' => $reply,
            ]);
        }

        return $reply;
    }

    /**
     * Generar la respuesta usando Prism LLM con fallback resiliente.
     */
    protected function generateAiResponse(Contact $contact, string $messageText, ?Business $business): string
    {
        $provider = app(SkillRegistry::class)->for($business?->vertical);
        $tools = $provider->getTools($contact);
        $tz = $business?->timezone ?? config('app.timezone', 'America/Guayaquil');

        $systemPrompt = $this->buildSystemPrompt($business, $contact, $tz, $provider);

        // Cargar historial reciente de conversación para el contexto
        $messages = Message::where('contact_id', $contact->id)
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get()
            ->reverse();

        $prismMessages = [];
        foreach ($messages as $msg) {
            if ($msg->role === 'user') {
                $prismMessages[] = new UserMessage($msg->content);
            } else {
                $prismMessages[] = new AssistantMessage($msg->content);
            }
        }

        // 1. Primer intento: DeepSeek
        try {
            return $this->executePrismCall('deepseek', 'deepseek-chat', $systemPrompt, $prismMessages, $tools);
        } catch (Throwable $e) {
            Log::warning('Fallo en proveedor primario DeepSeek. Intentando fallback a OpenAI: '.$e->getMessage());

            // 2. Fallback: OpenAI GPT-4o-mini
            try {
                return $this->executePrismCall('openai', 'gpt-4o-mini', $systemPrompt, $prismMessages, $tools);
            } catch (Throwable $e2) {
                Log::error('Fallo también en proveedor secundario OpenAI: '.$e2->getMessage());
                throw $e2;
            }
        }
    }

    /**
     * Ejecuta llamada a la API usando la librería Prism.
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
            ->withMaxSteps(5)
            ->generate();

        return $response->text;
    }

    /**
     * Construye el prompt del sistema estructurado en 3 capas (Identidad Base + Personalidad Negocio + Prompt Vertical).
     */
    public function buildSystemPrompt(?Business $business, Contact $contact, string $tz, ?SkillProvider $provider = null): string
    {
        $provider = $provider ?? app(SkillRegistry::class)->for($business?->vertical);

        $now = Carbon::now($tz)->format('Y-m-d H:i (l)');
        $businessName = $business?->name ?? 'Negocio Principal';
        $hoursStart = $business?->business_hours_start ?? '09:00';
        $hoursEnd = $business?->business_hours_end ?? '18:00';
        $slotDuration = $business?->slot_duration_minutes ?? 45;

        // Layer 1: Core Identity
        $layer1 = <<<LAYER1
[CAPA 1: IDENTIDAD BASE]
Eres Ualdo, el asistente virtual e inteligencia de atención del negocio '{$businessName}'.
Tu objetivo es atender amablemente a los usuarios por WhatsApp/Telegram, responder dudas sobre servicios/productos y ejecutar acciones operativas.

CONTEXTO DE TIEMPO Y NEGOCIO:
- Fecha y hora actual: {$now}
- Zona Horaria: {$tz}
- Horario comercial: {$hoursStart} a {$hoursEnd}
- Duración promedio de slot: {$slotDuration} minutos

INFORMACIÓN DEL CLIENTE:
- Nombre: {$contact->name}
- Teléfono: {$contact->phone_number}
LAYER1;

        // Layer 2: Custom Persona
        $rawPersona = $business?->settings['ai_persona'] ?? null;
        if (! empty($rawPersona)) {
            $cleanPersona = mb_substr(trim((string) $rawPersona), 0, 300);
            $layer2 = <<<LAYER2
[CAPA 2: PERSONALIDAD DEL NEGOCIO]
Aplica las siguientes preferencias de tono expresadas por el negocio:
"{$cleanPersona}"
LAYER2;
        } else {
            $layer2 = <<<'LAYER2'
[CAPA 2: PERSONALIDAD DEL NEGOCIO]
Utiliza un tono educado, empático, profesional y resolutivo. Usa español estándar.
LAYER2;
        }

        // Layer 3: Vertical Domain Rules
        $verticalSection = $provider->promptSection($business, $contact, $tz);
        $layer3 = <<<LAYER3
[CAPA 3: REGLAS DEL VERTICAL Y VOCABULARIO]
{$verticalSection}
LAYER3;

        return "{$layer1}\n\n{$layer2}\n\n{$layer3}";
    }
}
