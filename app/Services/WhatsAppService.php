<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppService
{
    protected string $phoneId;

    protected string $accessToken;

    protected string $version = 'v20.0';

    public function __construct()
    {
        $this->phoneId = (string) config('services.whatsapp.phone_id', '');
        $this->accessToken = (string) config('services.whatsapp.access_token', '');
    }

    /**
     * Resolve phone ID and access token for the active tenant or global fallback.
     *
     * @return array{0: string, 1: string}
     */
    protected function resolveCredentials(): array
    {
        $business = BusinessContext::get();
        if ($business) {
            $phoneId = ! empty($business->whatsapp_phone_number_id)
                ? $business->whatsapp_phone_number_id
                : $this->phoneId;

            $token = ! empty($business->whatsapp_access_token)
                ? (string) $business->whatsapp_access_token
                : (isset($business->settings['whatsapp_access_token']) && is_string($business->settings['whatsapp_access_token']) && ! empty($business->settings['whatsapp_access_token'])
                    ? $business->settings['whatsapp_access_token']
                    : $this->accessToken);

            return [$phoneId, $token];
        }

        return [$this->phoneId, $this->accessToken];
    }

    /**
     * Send text message via WhatsApp Cloud API using current tenant or fallback credentials.
     *
     * @return array<string, mixed>|null
     */
    public function sendText(string $to, string $text): ?array
    {
        [$phoneId, $accessToken] = $this->resolveCredentials();

        if (empty($phoneId) || empty($accessToken)) {
            Log::warning('WHATSAPP_PHONE_ID o WHATSAPP_ACCESS_TOKEN no están configurados para el negocio activo o global.');

            return null;
        }

        $url = "https://graph.facebook.com/{$this->version}/{$phoneId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'body' => $text,
            ],
        ];

        $response = Http::withToken($accessToken)->post($url, $payload);

        if ($response->failed()) {
            Log::error('WhatsApp Cloud API Send Error', [
                'phone_id' => $phoneId,
                'to' => $to,
                'response' => $response->json(),
            ]);
        } else {
            Log::info("Mensaje enviado exitosamente a WhatsApp ({$to}) desde PhoneID {$phoneId}: {$text}");
        }

        /** @var array<string, mixed>|null $result */
        $result = $response->json();

        return $result;
    }
}
