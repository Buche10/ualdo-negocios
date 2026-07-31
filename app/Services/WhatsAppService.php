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
        $this->phoneId = config('services.whatsapp.phone_id', '');
        $this->accessToken = config('services.whatsapp.access_token', '');
    }

    /**
     * Send text message via WhatsApp Cloud API.
     */
    public function sendText(string $to, string $text)
    {
        if (empty($this->phoneId) || empty($this->accessToken)) {
            Log::warning("WHATSAPP_PHONE_ID o WHATSAPP_ACCESS_TOKEN no están configurados en el archivo .env.");
            return null;
        }

        $url = "https://graph.facebook.com/{$this->version}/{$this->phoneId}/messages";

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => [
                'body' => $text
            ]
        ];

        $response = Http::withToken($this->accessToken)->post($url, $payload);

        if ($response->failed()) {
            Log::error("WhatsApp Cloud API Send Error", [
                'to' => $to,
                'response' => $response->json()
            ]);
        } else {
            Log::info("Mensaje enviado exitosamente a WhatsApp ({$to}): {$text}");
        }

        return $response->json();
    }
}
