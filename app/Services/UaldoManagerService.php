<?php

namespace App\Services;

use App\Models\CustomerMessage;
use App\Models\InventoryItem;
use App\Models\Appointment;
use App\Models\AiSkill;
use Illuminate\Support\Facades\Log;
use EchoLabs\Prism\Prism;
use EchoLabs\Prism\Enums\Provider;

class UaldoManagerService
{
    /**
     * Process an incoming message from a customer.
     */
    public function processIncomingMessage(string $sender, string $message, string $channel = 'whatsapp'): string
    {
        // 1. Save message to DB
        $customerMsg = CustomerMessage::create([
            'sender' => $sender,
            'message' => $message,
            'channel' => $channel,
            'status' => 'unread'
        ]);

        Log::info("Ualdo received message from {$sender}: {$message}");

        try {
            // 2. Use Prism (LLM) to determine intent and generate reply
            $response = $this->generateAiResponse($sender, $message);
        } catch (\Exception $e) {
            Log::error("Ualdo LLM error: " . $e->getMessage());
            $response = "Lo siento, en este momento tengo problemas técnicos para procesar tu solicitud. Intenta nuevamente más tarde.";
        }

        // 3. Mark as replied
        $customerMsg->update(['status' => 'replied']);

        return $response;
    }

    /**
     * Generate response using the LLM via Prism.
     */
    private function generateAiResponse(string $sender, string $message): string
    {
        // Fetch current context for the LLM
        $inventoryCount = InventoryItem::count();
        $appointmentsCount = Appointment::whereDate('start_time', today())->count();

        $systemPrompt = <<<PROMPT
Eres Ualdo, un AI Business Manager experto. 
Tu trabajo es atender a los clientes que envían mensajes al negocio.
Eres amable, profesional y resolutivo.

CONTEXTO ACTUAL DEL NEGOCIO:
- Tienes {$inventoryCount} productos registrados en el inventario universal.
- Tienes {$appointmentsCount} citas programadas para hoy.

INSTRUCCIONES CLAVES:
1. Si preguntan por inventario, responde de forma general sobre lo que sabes.
2. Si quieren reservar una cita, pídeles la fecha y hora.
3. Si el cliente pide crear un post o publicar en redes, infórmales que delegarás la tarea a tus "Skills de Contenido" y confirmarás cuando esté listo.

No uses formato Markdown complejo en tus respuestas a los clientes (ya que esto va a WhatsApp).
PROMPT;

        // Call the LLM using DeepSeek
        $prismResponse = Prism::text()
            ->using(Provider::DeepSeek, 'deepseek-chat')
            ->withSystemPrompt($systemPrompt)
            ->withPrompt($message)
            ->generate();

        return $prismResponse->text;
    }

    /**
     * Skill execution for image/post generation via OpenAI (Codex/DALL-E)
     * This will be implemented fully later.
     */
    public function generateImagePost(string $prompt)
    {
        // Here we will use OpenAI provider from Prism (or Http directly for DALL-E)
        // to generate the image based on a previously created AiSkill.
        // Prism::text()->using(Provider::OpenAI, 'dall-e-3')...
    }
}
