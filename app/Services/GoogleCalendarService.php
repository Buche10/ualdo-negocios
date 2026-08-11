<?php

namespace App\Services;

use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    /** @var array<string, mixed> */
    protected array $serviceAccount = [];

    protected string $calendarId;

    public function __construct()
    {
        $raw = config('services.google.service_account');
        if (empty($raw)) {
            $raw = config('services.google.service_account_json');
        }
        $this->calendarId = (string) config('services.google.calendar_id', 'primary');

        if (is_array($raw)) {
            $this->serviceAccount = $raw;
        } elseif (is_string($raw) && ! empty($raw)) {
            $trimmed = trim($raw);
            if (str_starts_with($trimmed, '{')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $this->serviceAccount = $decoded;
                }
            } elseif (file_exists($raw)) {
                $decoded = json_decode((string) file_get_contents($raw), true);
                if (is_array($decoded)) {
                    $this->serviceAccount = $decoded;
                }
            }
        }
    }

    /**
     * Synchronize a newly created appointment to Google Calendar.
     */
    public function syncAppointment(Appointment $appointment): ?string
    {
        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            Log::warning('Google Calendar sync skipped: No access token available.');

            return null;
        }

        $url = "https://www.googleapis.com/calendar/v3/calendars/{$this->calendarId}/events";

        $startTime = Carbon::parse($appointment->start_time)->timezone(config('app.timezone', 'America/Guayaquil'));
        $endTime = $appointment->end_time
            ? Carbon::parse($appointment->end_time)->timezone(config('app.timezone', 'America/Guayaquil'))
            : $startTime->copy()->addMinutes(45);

        $payload = [
            'summary' => $appointment->title,
            'description' => $appointment->description ?? 'Cita médica/odontológica agendada vía Ualdo AI WhatsApp',
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

                return (string) $eventId;
            } else {
                Log::error('Error enviando evento a Google Calendar API', $response->json() ?? []);
            }
        } catch (\Exception $e) {
            Log::error('Excepción en GoogleCalendarService: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Update an existing Google Calendar event for a rescheduled appointment.
     */
    public function updateAppointment(Appointment $appointment): bool
    {
        if (empty($appointment->google_event_id)) {
            return false;
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return false;
        }

        $tz = config('app.timezone', 'America/Guayaquil');
        $startTime = Carbon::parse($appointment->start_time)->timezone($tz);
        $endTime = $appointment->end_time
            ? Carbon::parse($appointment->end_time)->timezone($tz)
            : $startTime->copy()->addMinutes(45);

        $url = "https://www.googleapis.com/calendar/v3/calendars/{$this->calendarId}/events/{$appointment->google_event_id}";

        try {
            $response = Http::withToken($accessToken)->patch($url, [
                'summary' => $appointment->title,
                'description' => $appointment->description ?? 'Cita reprogramada vía Ualdo AI WhatsApp',
                'start' => ['dateTime' => $startTime->toRfc3339String(), 'timeZone' => $tz],
                'end' => ['dateTime' => $endTime->toRfc3339String(), 'timeZone' => $tz],
            ]);

            if ($response->successful()) {
                Log::info("Evento #{$appointment->google_event_id} actualizado en Google Calendar para Cita #{$appointment->id}");

                return true;
            } else {
                Log::error('Error actualizando evento en Google Calendar', $response->json() ?? []);
            }
        } catch (\Exception $e) {
            Log::error('Excepción al actualizar cita en Google Calendar: '.$e->getMessage());
        }

        return false;
    }

    /**
     * Delete/Cancel an event in Google Calendar. Accepts string event ID or Appointment model.
     */
    public function deleteAppointment(string|Appointment $event): bool
    {
        $eventId = $event instanceof Appointment ? $event->google_event_id : $event;

        if (empty($eventId)) {
            return false;
        }

        $accessToken = $this->getAccessToken();
        if (! $accessToken) {
            return false;
        }

        $url = "https://www.googleapis.com/calendar/v3/calendars/{$this->calendarId}/events/{$eventId}";

        try {
            $response = Http::withToken($accessToken)->delete($url);

            // 200/204 = borrado; 410 = ya no existe (idempotente, lo tratamos como éxito)
            if ($response->successful() || $response->status() === 410) {
                Log::info("Evento #{$eventId} borrado exitosamente de Google Calendar.");

                return true;
            }
            Log::error('Error borrando evento en Google Calendar', $response->json() ?? []);
        } catch (\Exception $e) {
            Log::error('Excepción al borrar evento de Google Calendar: '.$e->getMessage());
        }

        return false;
    }

    /**
     * Generate OAuth2 Access Token using Service Account JWT Grant.
     * Caches ONLY non-empty valid tokens (never caches null on transient errors).
     */
    protected function getAccessToken(): ?string
    {
        if (empty($this->serviceAccount) || empty($this->serviceAccount['client_email']) || empty($this->serviceAccount['private_key'])) {
            return null;
        }

        /** @var string|null $cachedToken */
        $cachedToken = Cache::get('google_calendar_access_token');
        if (is_string($cachedToken) && ! empty($cachedToken)) {
            return $cachedToken;
        }

        if (app()->environment('testing')) {
            return 'mock_access_token';
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

            $base64Header = $this->base64UrlEncode((string) json_encode($header));
            $base64ClaimSet = $this->base64UrlEncode((string) json_encode($claimSet));
            $signatureInput = $base64Header.'.'.$base64ClaimSet;

            $privateKey = (string) $this->serviceAccount['private_key'];
            openssl_sign($signatureInput, $signature, $privateKey, 'SHA256');

            $jwt = $signatureInput.'.'.$this->base64UrlEncode($signature);

            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt,
            ]);

            if ($response->successful()) {
                $token = (string) $response->json('access_token');
                if (! empty($token)) {
                    Cache::put('google_calendar_access_token', $token, 3300);

                    return $token;
                }
            } else {
                Log::error('Google Service Account Token Error', $response->json() ?? []);
            }
        } catch (\Exception $e) {
            Log::error('Excepción al generar Service Account JWT Token: '.$e->getMessage());
        }

        return null;
    }

    /**
     * Encode string to Base64URL.
     */
    protected function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
