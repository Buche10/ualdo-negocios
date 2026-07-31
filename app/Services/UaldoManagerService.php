<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Message;
use App\Models\InventoryItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Prism;
use Prism\Prism\Enums\Provider;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Throwable;

class UaldoManagerService
{
    protected HealthSkillsService $healthSkills;

    public function __construct(HealthSkillsService $healthSkills)
    {
        $this->healthSkills = $healthSkills;
    }

    /**
     * Process incoming message from WhatsApp / Web.
     */
    public function processIncomingMessage(string $senderPhone, string $messageText, string $channel = 'whatsapp', ?string $waId = null): string
    {
        $tz = config('app.timezone', 'America/Guayaquil');

        // 1. Obtener o crear Contacto / Paciente
        $contact = Contact::firstOrCreate(
            ['phone_number' => $senderPhone],
            ['name' => 'Paciente']
        );

        // 2. Registrar el mensaje entrante del usuario
        Message::create([
            'contact_id' => $contact->id,
            'role' => 'user',
            'content' => $messageText,
            'wa_id' => $waId,
        ]);

        // 3. Verificar si el bot está pausado para este paciente (Handoff humano)
        if ($contact->bot_paused_until && Carbon::parse($contact->bot_paused_until, $tz)->isFuture()) {
            Log::info("Bot pausado para paciente {$senderPhone} hasta {$contact->bot_paused_until}. Mensaje no procesado por IA.");
            return "";
        }

        // 4. Intentar generar respuesta con la IA (DeepSeek con fallback a OpenAI)
        try {
            $reply = $this->generateAiResponse($contact, $messageText);
        } catch (Throwable $e) {
            Log::error("Error general en UaldoManagerService: " . $e->getMessage(), [
                'exception' => $e
            ]);
            $reply = "Lo siento, en este momento tengo un problema técnico para procesar tu solicitud. Un asesor de nuestro consultorio te responderá pronto.";
        }

        // 5. Guardar la respuesta del asistente si no está vacía
        if (!empty($reply)) {
            Message::create([
                'contact_id' => $contact->id,
                'role' => 'assistant',
                'content' => $reply
            ]);
        }

        return $reply;
    }

    /**
     * Generate AI Response using Prism + Health Skills.
     */
    protected function generateAiResponse(Contact $contact, string $latestUserMessage): string
    {
        $tz = config('app.timezone', 'America/Guayaquil');
        $now = Carbon::now($tz)->format('Y-m-d H:i (l)');
        $servicesCount = InventoryItem::count();

        // 1. Historial reciente (hasta 20 mensajes)
        $rawHistory = Message::where('contact_id', $contact->id)
            ->orderBy('created_at', 'asc')
            ->take(20)
            ->get();

        $prismMessages = [];
        foreach ($rawHistory as $msg) {
            if ($msg->role === 'user') {
                $prismMessages[] = new UserMessage($msg->content ?? '');
            } elseif ($msg->role === 'assistant') {
                $prismMessages[] = new AssistantMessage($msg->content ?? '');
            }
        }

        // 2. System Prompt especializado para Consultorios de Salud con encuadre LOPDP
        $systemPrompt = <<<PROMPT
Eres Ualdo, la recepcionista médica virtual experta del consultorio de salud (atención médica, odontológica y estética). Zona horaria actual: America/Guayaquil (Guayaquil/Quito, Ecuador). Hoy es {$now}.

TU OBJETIVO PRINCIPAL:
Atender al paciente con empatía, claridad y eficiencia por WhatsApp. Responder sobre especialidades y tarifas, verificar disponibilidad de la agenda médica y confirmar citas sin solapamientos.

PROTECCIÓN DE DATOS (CUMPLIMIENTO LOPDP - ECUADOR):
- Trata toda información médica y personal bajo estricta confidencialidad médica conforme a la Ley Orgánica de Protección de Datos Personales (LOPDP).
- Si es el primer mensaje de interacción para agendar, incluye sutilmente la nota de consentimiento de datos de salud al confirmar los datos del paciente (ej: "Al confirmar tu cita, aceptas el tratamiento confidencial de tus datos para tu atención médica conforme a la LOPDP").

REGLAS DE OPERACIÓN CON HERRAMIENTAS:
1. SIEMPRE usa 'check_availability' antes de ofrecer horarios de cita al paciente.
2. NUNCA inventes tarifas ni servicios. Usa 'search_services' si te preguntan por costos o disponibilidad de tratamientos.
3. SIEMPRE solicita el nombre completo y motivo del paciente antes de ejecutar 'schedule_appointment'.
4. Usa 'transfer_to_human' si el paciente expresa una emergencia médica grave, una molestia severa o solicita hablar con un profesional humano.
5. Genera respuestas limpias, estructuradas y con lenguaje adecuado para WhatsApp.
PROMPT;

        // 3. Herramientas configuradas
        $tools = $this->healthSkills->getTools($contact);

        // 4. Invocación de Prism con DeepSeek y fallback a OpenAI
        return $this->executePrismCall($systemPrompt, $prismMessages, $tools);
    }

    /**
     * Call LLM via Prism with provider resiliency fallback.
     */
    protected function executePrismCall(string $systemPrompt, array $prismMessages, array $tools): string
    {
        $deepseekKey = config('prism.providers.deepseek.api_key');
        $openaiKey = config('prism.providers.openai.api_key');

        if (!empty($deepseekKey)) {
            try {
                Log::info("Invocando Prism con proveedor DeepSeek...");
                $response = Prism::text()
                    ->using(Provider::DeepSeek, 'deepseek-chat')
                    ->withSystemPrompt($systemPrompt)
                    ->withMessages($prismMessages)
                    ->withTools($tools)
                    ->withMaxSteps(5)
                    ->generate();

                return $response->text;
            } catch (Throwable $e) {
                Log::warning("DeepSeek tool-call falló: " . $e->getMessage() . ". Intentando fallback con OpenAI...");
            }
        }

        if (!empty($openaiKey)) {
            Log::info("Invocando Prism con proveedor OpenAI (gpt-4o-mini)...");
            $response = Prism::text()
                ->using(Provider::OpenAI, 'gpt-4o-mini')
                ->withSystemPrompt($systemPrompt)
                ->withMessages($prismMessages)
                ->withTools($tools)
                ->withMaxSteps(5)
                ->generate();

            return $response->text;
        }

        throw new \RuntimeException("No hay API Keys configuradas ni para DeepSeek ni para OpenAI en config.");
    }
}
