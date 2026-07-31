<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Contact;
use App\Models\InventoryItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Tool;

class HealthSkillsService
{
    protected GoogleCalendarService $calendarService;

    public function __construct(?GoogleCalendarService $calendarService = null)
    {
        $this->calendarService = $calendarService ?? new GoogleCalendarService();
    }

    /**
     * Get array of Prism tools configured for the Health Receptionist vertical.
     */
    public function getTools(Contact $contact): array
    {
        return [
            $this->checkAvailabilityTool(),
            $this->scheduleAppointmentTool($contact),
            $this->searchServicesTool(),
            $this->transferToHumanTool($contact),
        ];
    }

    /**
     * 1. Tool: check_availability
     */
    protected function checkAvailabilityTool(): Tool
    {
        return (new Tool())
            ->as('check_availability')
            ->for('Revisa la disponibilidad de citas en una fecha específica en el consultorio.')
            ->withStringParameter('date', 'Fecha a consultar en formato YYYY-MM-DD', true)
            ->using(function (string $date) {
                try {
                    $tz = config('app.timezone', 'America/Guayaquil');
                    $dayStart = Carbon::parse("{$date} 00:00:00", $tz);
                    $dayEnd = Carbon::parse("{$date} 23:59:59", $tz);

                    $appointments = Appointment::whereBetween('start_time', [$dayStart, $dayEnd])
                        ->where('status', '!=', 'cancelled')
                        ->get(['start_time', 'end_time', 'title'])
                        ->map(fn($app) => [
                            'from' => Carbon::parse($app->start_time)->timezone($tz)->format('H:i'),
                            'to' => Carbon::parse($app->end_time)->timezone($tz)->format('H:i'),
                        ])
                        ->toArray();

                    return json_encode([
                        'status' => 'success',
                        'timezone' => 'America/Guayaquil (GMT-5)',
                        'business_hours' => '09:00 a 18:00 (lunes a sábado)',
                        'appointment_duration' => '45 minutos',
                        'booked_intervals' => $appointments,
                        'instruction' => 'Ofrece horas disponibles dentro de business_hours que no tengan solapamiento con los booked_intervals. Las citas duran 45 minutos.'
                    ], JSON_UNESCAPED_UNICODE);
                } catch (\Exception $e) {
                    Log::error("Error en check_availability: " . $e->getMessage());
                    return json_encode(['status' => 'error', 'message' => 'Error al consultar disponibilidad.'], JSON_UNESCAPED_UNICODE);
                }
            });
    }

    /**
     * 2. Tool: schedule_appointment (con guard anti-solapamiento de rango y sync a Google Calendar)
     */
    protected function scheduleAppointmentTool(Contact $contact): Tool
    {
        return (new Tool())
            ->as('schedule_appointment')
            ->for('Agenda una cita médica/odontológica/estética para el paciente en el consultorio.')
            ->withStringParameter('datetime', 'Fecha y hora exacta de la cita en formato YYYY-MM-DD HH:MM', true)
            ->withStringParameter('title', 'Motivo o tipo de consulta/tratamiento', true)
            ->using(function (string $datetime, string $title) use ($contact) {
                try {
                    $tz = config('app.timezone', 'America/Guayaquil');
                    $startTime = Carbon::parse($datetime, $tz);
                    $endTime = $startTime->copy()->addMinutes(45);

                    return DB::transaction(function () use ($startTime, $endTime, $title, $contact, $tz) {
                        // Guard anti-solapamiento estricto por rango de tiempo (start_time < newEnd AND end_time > newStart)
                        $overlap = Appointment::where('status', '!=', 'cancelled')
                            ->where(function ($q) use ($startTime, $endTime) {
                                $q->where('start_time', '<', $endTime)
                                  ->where('end_time', '>', $startTime);
                            })
                            ->lockForUpdate()
                            ->exists();

                        if ($overlap) {
                            return json_encode([
                                'status' => 'error',
                                'message' => 'El horario solicitado se solapa con otra cita existente en el consultorio. Por favor pide al paciente que elija un horario diferente.'
                            ], JSON_UNESCAPED_UNICODE);
                        }

                        $appointment = Appointment::create([
                            'contact_id' => $contact->id,
                            'title' => $title,
                            'description' => "Cita agendada vía WhatsApp para el paciente {$contact->name} ({$contact->phone_number})",
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                            'status' => 'scheduled',
                            'customer_info' => [
                                'patient_name' => $contact->name,
                                'phone' => $contact->phone_number,
                            ]
                        ]);

                        // Sincronizar automáticamente con Google Calendar
                        $gCalEventId = $this->calendarService->syncAppointment($appointment);

                        return json_encode([
                            'status' => 'success',
                            'message' => "Cita reservada con éxito para el {$startTime->format('d/m/Y a las H:i')} (Zona horaria Guayaquil). Motivo: {$title}.",
                            'google_calendar_synced' => !empty($gCalEventId)
                        ], JSON_UNESCAPED_UNICODE);
                    });
                } catch (\Exception $e) {
                    Log::error("Error en schedule_appointment: " . $e->getMessage());
                    return json_encode(['status' => 'error', 'message' => 'Error interno al intentar guardar la cita.'], JSON_UNESCAPED_UNICODE);
                }
            });
    }

    /**
     * 3. Tool: search_services (sobre InventoryItem)
     */
    protected function searchServicesTool(): Tool
    {
        return (new Tool())
            ->as('search_services')
            ->for('Busca servicios, tratamientos, especialidades o precios ofrecidos por el consultorio.')
            ->withStringParameter('query', 'Término a buscar (ej: limpieza dental, consulta general, blanqueamiento, costo, etc.)', true)
            ->using(function (string $query) {
                try {
                    $services = InventoryItem::where(function ($q) use ($query) {
                        $q->where('name', 'like', "%{$query}%")
                          ->orWhere('description', 'like', "%{$query}%");
                    })
                    ->take(10)
                    ->get(['name', 'description', 'type', 'price']);

                    if ($services->isEmpty()) {
                        $allServices = InventoryItem::take(10)->get(['name', 'description', 'price']);
                        return json_encode([
                            'status' => 'success',
                            'message' => "No se encontró coincidencia directa para '{$query}', pero aquí tienes la lista general de servicios del consultorio:",
                            'services' => $allServices->toArray()
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    return json_encode([
                        'status' => 'success',
                        'services' => $services->toArray()
                    ], JSON_UNESCAPED_UNICODE);
                } catch (\Exception $e) {
                    Log::error("Error en search_services: " . $e->getMessage());
                    return json_encode(['status' => 'error', 'message' => 'Error al consultar servicios.'], JSON_UNESCAPED_UNICODE);
                }
            });
    }

    /**
     * 4. Tool: transfer_to_human (handoff + pausa 24h)
     */
    protected function transferToHumanTool(Contact $contact): Tool
    {
        return (new Tool())
            ->as('transfer_to_human')
            ->for('Pausa la IA y transfiere la conversación al personal médico/recepcionista humano cuando el cliente lo pida o haya una urgencia.')
            ->withStringParameter('reason', 'Motivo de la transferencia o urgencia expresada por el paciente', true)
            ->using(function (string $reason) use ($contact) {
                try {
                    $tz = config('app.timezone', 'America/Guayaquil');
                    $contact->update(['bot_paused_until' => Carbon::now($tz)->addHours(24)]);
                    Log::info("Bot pausado por 24h para paciente {$contact->phone_number}. Motivo: {$reason}");

                    return json_encode([
                        'status' => 'success',
                        'message' => 'El bot ha sido pausado. Informa amablemente al paciente que la recepcionista del consultorio se pondrá en contacto con él a la brevedad.'
                    ], JSON_UNESCAPED_UNICODE);
                } catch (\Exception $e) {
                    Log::error("Error en transfer_to_human: " . $e->getMessage());
                    return json_encode(['status' => 'error', 'message' => 'Error al transferir a asesor humano.'], JSON_UNESCAPED_UNICODE);
                }
            });
    }
}
