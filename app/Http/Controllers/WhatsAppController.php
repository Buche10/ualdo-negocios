<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\UaldoManagerService;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    protected UaldoManagerService $ualdoService;
    protected WhatsAppService $whatsapp;

    public function __construct(UaldoManagerService $ualdoService, WhatsAppService $whatsapp)
    {
        $this->ualdoService = $ualdoService;
        $this->whatsapp = $whatsapp;
    }

    /**
     * Webhook verification for WhatsApp Meta Cloud API.
     */
    public function verifyWebhook(Request $request)
    {
        $verifyToken = env('WHATSAPP_VERIFY_TOKEN', 'ualdo_business_token');

        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode && $token) {
            if ($mode === 'subscribe' && $token === $verifyToken) {
                Log::info('WhatsApp Webhook Verified');
                return response($challenge, 200);
            }
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return response()->json(['error' => 'Bad Request'], 400);
    }

    /**
     * Handle incoming WhatsApp messages.
     */
    public function handleWebhook(Request $request)
    {
        $payload = $request->all();

        if (isset($payload['object']) && $payload['object'] === 'whatsapp_business_account') {
            foreach ($payload['entry'] as $entry) {
                foreach ($entry['changes'] as $change) {
                    if (isset($change['value']['messages'])) {
                        $messageData = $change['value']['messages'][0];
                        $contactsData = $change['value']['contacts'][0] ?? [];

                        $senderPhone = $messageData['from'] ?? 'unknown';
                        $waId = $messageData['id'] ?? null;
                        $messageText = $messageData['text']['body'] ?? '';

                        // Extraer el nombre si está presente en la carga útil de WhatsApp
                        $profileName = $contactsData['profile']['name'] ?? null;

                        if (!empty($messageText)) {
                            Log::info("WhatsApp Incoming Message from {$senderPhone} ({$profileName}): {$messageText}");

                            // Procesar mediante UaldoManagerService (IA + Tools)
                            $reply = $this->ualdoService->processIncomingMessage($senderPhone, $messageText, 'whatsapp', $waId);

                            // Enviar la respuesta directamente a WhatsApp
                            if (!empty($reply)) {
                                $this->whatsapp->sendText($senderPhone, $reply);
                            }
                        }
                    }
                }
            }
            return response('EVENT_RECEIVED', 200);
        }

        return response()->json(['status' => 'not_found'], 404);
    }
}

