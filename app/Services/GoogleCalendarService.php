<?php

namespace App\Services;

use App\Models\Appointment;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoogleCalendarService
{
    protected string $calendarId;
    protected string $apiKey;

    public function __construct()
    {
        $this->calendarId = env('GOOGLE_CALENDAR_ID', 'primary');
        $this->apiKey = env('GOOGLE_CALENDAR_API_KEY', '');
    }

    /**
     * Synchronize an Appointment to Google Calendar.
     */
    public function syncAppointment(Appointment $appointment): ?string
    {
        if (empty($this->apiKey)) {
            Log::warning("GOOGLE_CALENDAR_API_KEY no configurada. Omitiendo sincronización con Google Calendar.");
            return null;
        }

        $url = "https://www.googleapis.com/calendar/v3/calendars/{$this->calendarId}/events?key={$this->apiKey}";

        $payload = [
            'summary' => $appointment->title,
            'description' => $appointment->description ?? "Cita de paciente registrada vía WhatsApp",
            'start' => [
                'dateTime' => Carbon::parse($appointment->start_time)->toRfc3339String(),
                'timeZone' => config('app.timezone', 'America/Guayaquil'),
            ],
            'end' => [
                'dateTime' => Carbon::parse($appointment->end_time ?? Carbon::parse($appointment->start_time)->addMinutes(45))->toRfc3339String(),
                'timeZone' => config('app.timezone', 'America/Guayaquil'),
            ],
        ];

        try {
            $response = Http::post($url, $payload);
            if ($response->successful()) {
                $eventId = $response->json('id');
                Log::info("Cita #{$appointment->id} sincronizada con Google Calendar (Event ID: {$eventId})");
                return $eventId;
            } else {
                Log::error("Error sincronizando evento con Google Calendar", $response->json() ?? []);
            }
        } catch (\Exception $e) {
            Log::error("Excepción en GoogleCalendarService: " . $e->getMessage());
        }

        return null;
    }
}
