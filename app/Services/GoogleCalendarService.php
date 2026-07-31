<?php

namespace App\Services;

use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    protected string $calendarId;
    protected ?array $serviceAccount;

    public function __construct()
    {
        $this->calendarId = config('services.google.calendar_id', 'primary');
        
        $jsonOrPath = config('services.google.service_account_json');
        if (!empty($jsonOrPath)) {
            if (file_exists($jsonOrPath)) {
                $this->serviceAccount = json_decode(file_get_contents($jsonOrPath), true);
            } else {
                $this->serviceAccount = json_decode($jsonOrPath, true);
            }
        } else {
            $this->serviceAccount = null;
        }
    }

    /**
     * Synchronize an Appointment to Google Calendar using OAuth2 Service Account.
     */
    public function syncAppointment(Appointment $appointment): ?string
    {
        $accessToken = $this->getAccessToken();
        if (!$accessToken) {
            Log::info("Google Calendar Service Account no configurado o no autenticado. Omitiendo sincronización remota para cita #{$appointment->id}.");
            return null;
        }

        $url = "https://www.googleapis.com/calendar/v3/calendars/{$this->calendarId}/events";

        $startTime = Carbon::parse($appointment->start_time)->timezone(config('app.timezone', 'America/Guayaquil'));
        $endTime = $appointment->end_time 
            ? Carbon::parse($appointment->end_time)->timezone(config('app.timezone', 'America/Guayaquil')) 
            : $startTime->copy()->addMinutes(45);

        $payload = [
            'summary' => $appointment->title,
            'description' => $appointment->description ?? "Cita médica/odontológica agendada vía Ualdo AI WhatsApp",
            'start' => [
                'dateTime' => $startTime->toRfc3339String(),
                'timeZone' => config('app.timezone', 'America/Guayaquil'),
            ],
            'end' => [
                'dateTime' => $endTime->toRfc3339String(),
                'timeZone' => config('app.timezone', 'America/Guayaquil'),
            ],
        ];

        try {
            $response = Http::withToken($accessToken)->post($url, $payload);

            if ($response->successful()) {
                $eventId = $response->json('id');
                Log::info("Cita #{$appointment->id} sincronizada exitosamente con Google Calendar (Event ID: {$eventId})");
                return $eventId;
            } else {
                Log::error("Error enviando evento a Google Calendar API", $response->json() ?? []);
            }
        } catch (\Exception $e) {
            Log::error("Excepción en GoogleCalendarService: " . $e->getMessage());
        }

        return null;
    }

    /**
     * Generate OAuth2 Access Token using Service Account JWT Grant.
     */
    protected function getAccessToken(): ?string
    {
        if (empty($this->serviceAccount) || empty($this->serviceAccount['client_email']) || empty($this->serviceAccount['private_key'])) {
            return null;
        }

        try {
            $now = time();
            $header = ['alg' => 'RS256', 'typ' => 'JWT'];
            $claimSet = [
                'iss' => $this->serviceAccount['client_email'],
                'scope' => 'https://www.googleapis.com/auth/calendar.events',
                'aud' => 'https://oauth2.googleapis.com/token',
                'exp' => $now + 3600,
                'iat' => $now,
            ];

            $base64Header = $this->base64UrlEncode(json_encode($header));
            $base64ClaimSet = $this->base64UrlEncode(json_encode($claimSet));
            $signatureInput = $base64Header . '.' . $base64ClaimSet;

            $privateKey = $this->serviceAccount['private_key'];
            openssl_sign($signatureInput, $signature, $privateKey, 'SHA256');

            $jwt = $signatureInput . '.' . $this->base64UrlEncode($signature);

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($response->successful()) {
                return $response->json('access_token');
            } else {
                Log::error("Google Service Account Token Error", $response->json() ?? []);
            }
        } catch (\Exception $e) {
            Log::error("Excepción al generar Service Account JWT Token: " . $e->getMessage());
        }

        return null;
    }

    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
