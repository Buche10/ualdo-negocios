<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Services\UaldoManagerService;

class WhatsAppController extends Controller
{
    protected UaldoManagerService $ualdoService;

    public function __construct(UaldoManagerService $ualdoService)
    {
        $this->ualdoService = $ualdoService;
    }

    /**
     * Webhook verification for WhatsApp API.
     */
    public function verifyWebhook(Request $request)
    {
        $verifyToken = env('WHATSAPP_VERIFY_TOKEN', 'ualdo_business_token');

        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode && $token) {
            if ($mode === 'subscribe' && $token === $verifyToken) {
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

        // Very basic parsing of WhatsApp Cloud API payload
        if (isset($payload['entry'][0]['changes'][0]['value']['messages'][0])) {
            $messageData = $payload['entry'][0]['changes'][0]['value']['messages'][0];
            $senderPhone = $messageData['from'] ?? 'unknown';
            
            $messageText = '';
            if (isset($messageData['text']['body'])) {
                $messageText = $messageData['text']['body'];
            }

            if (!empty($messageText)) {
                // Pass it to Ualdo Manager
                $reply = $this->ualdoService->processIncomingMessage($senderPhone, $messageText, 'whatsapp');

                // Here we would use Http facade to send the $reply back to the WhatsApp API
                // Http::withToken(env('WHATSAPP_TOKEN'))->post('...', ['to' => $senderPhone, 'text' => ['body' => $reply]]);
            }
        }

        return response()->json(['status' => 'ok']);
    }
}
