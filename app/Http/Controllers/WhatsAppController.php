<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWhatsAppWebhookJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    /**
     * Webhook verification for WhatsApp Meta Cloud API.
     */
    public function verifyWebhook(Request $request): Response|JsonResponse
    {
        $verifyToken = (string) config('services.whatsapp.verify_token', '');
        if (empty($verifyToken)) {
            Log::error('El token de verificación de WhatsApp (WHATSAPP_VERIFY_TOKEN) no está configurado.');

            return response()->json(['error' => 'Verify token not configured'], 403);
        }

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
     * Handle incoming WhatsApp webhook messages (Asynchronous Queue Dispatch + Signature Check).
     */
    public function handleWebhook(Request $request): Response|JsonResponse
    {
        $appSecret = (string) config('services.whatsapp.app_secret', '');

        // Validar firma HMAC SHA256 si la appSecret está configurada o en producción
        if (! empty($appSecret) || app()->isProduction()) {
            $signatureHeader = $request->header('X-Hub-Signature-256');
            if (! $signatureHeader || empty($appSecret) || ! $this->isValidSignature($request->getContent(), $signatureHeader, $appSecret)) {
                Log::warning('Firma X-Hub-Signature-256 de WhatsApp inválida o no provista.');

                return response()->json(['error' => 'Invalid signature'], 401);
            }
        }

        $payload = $request->all();

        if (isset($payload['object']) && $payload['object'] === 'whatsapp_business_account') {
            // Despachar a la cola para no bloquear la respuesta HTTP (Responde a Meta en <50ms)
            ProcessWhatsAppWebhookJob::dispatch($payload);

            return response('EVENT_RECEIVED', 200);
        }

        return response()->json(['status' => 'not_found'], 404);
    }

    /**
     * Verify Meta HMAC SHA256 signature.
     */
    protected function isValidSignature(string $payload, string $signatureHeader, string $appSecret): bool
    {
        $expectedHash = hash_hmac('sha256', $payload, $appSecret);
        $providedHash = str_replace('sha256=', '', $signatureHeader);

        return hash_equals($expectedHash, $providedHash);
    }
}
