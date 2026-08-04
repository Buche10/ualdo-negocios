<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Doctor;
use App\Models\InventoryItem;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Tool;

class HealthSkillsService
{
    protected GoogleCalendarService $calendarService;

    protected TelegramService $telegramService;

    protected PaymentService $paymentService;

    public function __construct(
        ?GoogleCalendarService $calendarService = null,
        ?TelegramService $telegramService = null,
        ?PaymentService $paymentService = null
    ) {
        $this->calendarService = $calendarService ?? new GoogleCalendarService;
        $this->telegramService = $telegramService ?? new TelegramService;
        $this->paymentService = $paymentService ?? new PaymentService;
    }

    /**
     * Helper to get active business configuration.
     */
    protected function getActiveBusiness(?Contact $contact = null): ?Business
    {
        return BusinessContext::get() ?? $contact?->business ?? Business::first();
    }

    /**
     * Get array of Prism tools configured for the Health Receptionist vertical.
     */
    public function getTools(?Contact $contact = null): array
    {
        return [
            $this->checkAvailabilityTool($contact),
            $this->scheduleAppointmentTool($contact),
            $this->rescheduleAppointmentTool($contact),
            $this->cancelAppointmentTool($contact),
            $this->searchServicesTool(),
            $this->generatePaymentLinkTool($contact),
            $this->transferToHumanTool($contact),
        ];
    }

    /**
     * 1. Tool: check_availability
     */
    protected function checkAvailabilityTool(?Contact $contact = null): Tool
    {
        return (new Tool)
            ->as('check_availability')
            ->for('Revisa la disponibilidad de citas en una fecha específica en el consultorio, opcionalmente filtrando por doctor o especialidad.')
            ->withStringParameter('date', 'Fecha a consultar en formato YYYY-MM-DD', true)
            ->withStringParameter('doctor_name', 'Nombre del doctor o especialista (opcional)', false)
            ->using(function (string $date, ?string $doctor_name = null) use ($contact) {
                try {
                    $business = $this->getActiveBusiness($contact);
                    $tz = $business?->timezone ?? config('app.timezone', 'America/Guayaquil');
                    $hoursStart = $business?->business_hours_start ?? '09:00';
                    $hoursEnd = $business?->business_hours_end ?? '18:00';
                    $slotDuration = $business?->slot_duration_minutes ?? 45;

                    $dayStart = Carbon::parse("{$date} 00:00:00", $tz);
                    $dayEnd = Carbon::parse("{$date} 23:59:59", $tz);

                    $doctors = Doctor::where('is_active', true)->get(['id', 'name', 'specialty']);

                    $query = Appointment::whereBetween('start_time', [$dayStart, $dayEnd])
                        ->where('status', '!=', 'cancelled');

                    if ($doctor_name) {
                        $doc = Doctor::where('name', 'like', "%{$doctor_name}%")->first();
                        if ($doc) {
                            $query->where('doctor_id', $doc->id);
                        }
                    }

                    $appointments = $query->get(['start_time', 'end_time', 'title', 'doctor_id'])
                        ->map(fn ($app) => [
                            'from' => Carbon::parse($app->start_time)->timezone($tz)->format('H:i'),
                            'to' => Carbon::parse($app->end_time)->timezone($tz)->format('H:i'),
                        ])
                        ->toArray();

                    $workingDays = $business?->working_days;
                    if (is_array($workingDays) && ! empty($workingDays)) {
                        $map = [
                            'monday' => 'lunes',
                            'tuesday' => 'martes',
                            'wednesday' => 'miércoles',
                            'thursday' => 'jueves',
                            'friday' => 'viernes',
                            'saturday' => 'sábado',
                            'sunday' => 'domingo',
                        ];
                        $translated = array_map(fn ($d) => $map[strtolower($d)] ?? $d, $workingDays);
                        $workingDaysLabel = implode(', ', $translated);
                    } else {
                        $workingDaysLabel = 'lunes a sábado';
                    }

                    return json_encode([
                        'status' => 'success',
                        'timezone' => $tz,
                        'business_hours' => "{$hoursStart} a {$hoursEnd} ({$workingDaysLabel})",
                        'appointment_duration' => "{$slotDuration} minutos",
                        'doctors_available' => $doctors->toArray(),
                        'booked_intervals' => $appointments,
                    ], JSON_UNESCAPED_UNICODE);
                } catch (\Exception $e) {
                    Log::error('Error en check_availability: '.$e->getMessage());

                    return json_encode(['status' => 'error', 'message' => 'Error al consultar disponibilidad.'], JSON_UNESCAPED_UNICODE);
                }
            });
    }

    /**
     * 2. Tool: schedule_appointment (con guard anti-solapamiento y descuento de insumos)
     */
    protected function scheduleAppointmentTool(?Contact $contact = null): Tool
    {
        return (new Tool)
            ->as('schedule_appointment')
            ->for('Agenda una cita médica/odontológica/estética para el paciente en el consultorio.')
            ->withStringParameter('datetime', 'Fecha y hora exacta de la cita en formato YYYY-MM-DD HH:MM', true)
            ->withStringParameter('title', 'Motivo o tipo de consulta/tratamiento', true)
            ->withStringParameter('doctor_name', 'Nombre del doctor asignado (opcional)', false)
            ->using(function (string $datetime, string $title, ?string $doctor_name = null) use ($contact) {
                try {
                    $business = $this->getActiveBusiness($contact);
                    $tz = $business?->timezone ?? config('app.timezone', 'America/Guayaquil');
                    $slotDuration = $business?->slot_duration_minutes ?? 45;

                    $startTime = Carbon::parse($datetime, $tz);
                    $endTime = $startTime->copy()->addMinutes($slotDuration);

                    return DB::transaction(function () use ($startTime, $endTime, $title, $doctor_name, $contact, $tz, $business) {
                        $doctor = null;
                        if ($doctor_name) {
                            $doctor = Doctor::where('name', 'like', "%{$doctor_name}%")->first();
                        }

                        // Guard anti-solapamiento estricto
                        $query = Appointment::where('status', '!=', 'cancelled')
                            ->where('start_time', '<', $endTime)
                            ->where('end_time', '>', $startTime);

                        if ($doctor) {
                            $query->where('doctor_id', $doctor->id);
                        }

                        if ($query->lockForUpdate()->exists()) {
                            return json_encode([
                                'status' => 'error',
                                'message' => 'El horario solicitado se solapa con otra cita existente en el consultorio. Por favor pide al paciente que elija un horario diferente.',
                            ], JSON_UNESCAPED_UNICODE);
                        }

                        $appointment = Appointment::create([
                            'contact_id' => $contact->id,
                            'doctor_id' => $doctor?->id,
                            'title' => $title,
                            'description' => "Cita agendada vía WhatsApp para el paciente {$contact->name} ({$contact->phone_number})",
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                            'status' => 'scheduled',
                            'customer_info' => [
                                'patient_name' => $contact->name,
                                'phone' => $contact->phone_number,
                                'doctor_name' => $doctor?->name,
                            ],
                        ]);

                        // Descuento automático de insumos vinculados al servicio si aplica
                        $serviceItem = InventoryItem::services()->where('name', 'like', "%{$title}%")->first();
                        if ($serviceItem) {
                            foreach ($serviceItem->requiredSupplies as $supply) {
                                $qty = $supply->pivot->quantity_required;
                                if ($supply->stock >= $qty) {
                                    $supply->decrement('stock', $qty);
                                    Log::info("Descontado {$qty} unidades de {$supply->name} para la cita {$appointment->id}");
                                }
                            }
                        }

                        // Sync a Google Calendar
                        $gCalEventId = $this->calendarService->syncAppointment($appointment);
                        if (! empty($gCalEventId)) {
                            $appointment->update(['google_event_id' => $gCalEventId]);
                        }

                        $docMsg = $doctor ? " con el/la Dr(a). {$doctor->name}" : '';

                        return json_encode([
                            'status' => 'success',
                            'message' => "Cita reservada con éxito para el {$startTime->format('d/m/Y a las H:i')}{$docMsg} (Zona horaria {$tz}). Motivo: {$title}.",
                            'google_calendar_synced' => ! empty($gCalEventId),
                        ], JSON_UNESCAPED_UNICODE);
                    });
                } catch (\Exception $e) {
                    Log::error('Error en schedule_appointment: '.$e->getMessage());

                    return json_encode(['status' => 'error', 'message' => 'Error interno al intentar guardar la cita.'], JSON_UNESCAPED_UNICODE);
                }
            });
    }

    /**
     * Resolver la cita futura del paciente a gestionar (cancelar/reprogramar).
     */
    protected function resolvePatientAppointment(?Contact $contact = null, ?string $datetime = null): array
    {
        if (! $contact) {
            return [null, json_encode([
                'status' => 'error',
                'message' => 'No hay paciente en contexto para consultar citas.',
            ], JSON_UNESCAPED_UNICODE)];
        }
        $business = $this->getActiveBusiness($contact);
        $tz = $business?->timezone ?? config('app.timezone', 'America/Guayaquil');

        $query = Appointment::where('contact_id', $contact->id)
            ->where('status', 'scheduled')
            ->where('start_time', '>=', Carbon::now($tz))
            ->orderBy('start_time', 'asc');

        $upcoming = $query->get();

        if ($upcoming->isEmpty()) {
            return [null, json_encode([
                'status' => 'error',
                'message' => 'El paciente no tiene citas futuras agendadas para gestionar.',
            ], JSON_UNESCAPED_UNICODE)];
        }

        if (! empty($datetime)) {
            $target = Carbon::parse($datetime, $tz);
            $match = $upcoming->first(fn ($a) => Carbon::parse($a->start_time)->timezone($tz)->equalTo($target));
            if (! $match) {
                return [null, json_encode([
                    'status' => 'error',
                    'message' => "No se encontró una cita del paciente que inicie el {$target->format('d/m/Y H:i')}. Pídele que confirme la fecha y hora exactas.",
                ], JSON_UNESCAPED_UNICODE)];
            }

            return [$match, null];
        }

        if ($upcoming->count() === 1) {
            return [$upcoming->first(), null];
        }

        $list = $upcoming->map(fn ($a) => [
            'datetime' => Carbon::parse($a->start_time)->timezone($tz)->format('Y-m-d H:i'),
            'title' => $a->title,
        ])->toArray();

        return [null, json_encode([
            'status' => 'needs_clarification',
            'message' => 'El paciente tiene varias citas futuras. Pregúntale cuál desea gestionar indicando la fecha y hora.',
            'appointments' => $list,
        ], JSON_UNESCAPED_UNICODE)];
    }

    /**
     * 3. Tool: reschedule_appointment
     */
    protected function rescheduleAppointmentTool(?Contact $contact = null): Tool
    {
        return (new Tool)
            ->as('reschedule_appointment')
            ->for('Reprograma una cita existente del paciente a un nuevo horario disponible.')
            ->withStringParameter('new_datetime', 'Nueva fecha y hora de la cita (YYYY-MM-DD HH:MM)', true)
            ->withStringParameter('current_datetime', 'Fecha y hora actual de la cita (YYYY-MM-DD HH:MM). Opcional si solo tiene una cita.', false)
            ->using(function (string $new_datetime, ?string $current_datetime = null) use ($contact) {
                try {
                    $business = $this->getActiveBusiness($contact);
                    $tz = $business?->timezone ?? config('app.timezone', 'America/Guayaquil');
                    $slotDuration = $business?->slot_duration_minutes ?? 45;

                    return DB::transaction(function () use ($new_datetime, $current_datetime, $contact, $tz, $slotDuration) {
                        [$appointment, $error] = $this->resolvePatientAppointment($contact, $current_datetime);
                        if ($error) {
                            return $error;
                        }

                        $newStart = Carbon::parse($new_datetime, $tz);
                        $newEnd = $newStart->copy()->addMinutes($slotDuration);

                        $overlap = Appointment::where('status', '!=', 'cancelled')
                            ->where('id', '!=', $appointment->id)
                            ->where('start_time', '<', $newEnd)
                            ->where('end_time', '>', $newStart)
                            ->lockForUpdate()
                            ->exists();

                        if ($overlap) {
                            return json_encode([
                                'status' => 'error',
                                'message' => 'El nuevo horario se solapa con otra cita existente. Pide al paciente que elija un horario diferente.',
                            ], JSON_UNESCAPED_UNICODE);
                        }

                        $appointment->update([
                            'start_time' => $newStart,
                            'end_time' => $newEnd,
                        ]);

                        $synced = $this->calendarService->updateAppointment($appointment);

                        return json_encode([
                            'status' => 'success',
                            'message' => "Cita reprogramada con éxito para el {$newStart->format('d/m/Y a las H:i')} (Zona horaria {$tz}).",
                            'google_calendar_synced' => $synced,
                        ], JSON_UNESCAPED_UNICODE);
                    });
                } catch (\Exception $e) {
                    Log::error('Error en reschedule_appointment: '.$e->getMessage());

                    return json_encode(['status' => 'error', 'message' => 'Error interno al reprogramar la cita.'], JSON_UNESCAPED_UNICODE);
                }
            });
    }

    /**
     * 4. Tool: cancel_appointment
     */
    protected function cancelAppointmentTool(?Contact $contact = null): Tool
    {
        return (new Tool)
            ->as('cancel_appointment')
            ->for('Cancela una cita futura del paciente, liberando el horario en la agenda.')
            ->withStringParameter('datetime', 'Fecha y hora de la cita a cancelar (YYYY-MM-DD HH:MM). Opcional si el paciente solo tiene una cita.', false)
            ->using(function (?string $datetime = null) use ($contact) {
                try {
                    [$appointment, $error] = $this->resolvePatientAppointment($contact, $datetime);
                    if ($error) {
                        return $error;
                    }

                    $business = $this->getActiveBusiness($contact);
                    $tz = $business?->timezone ?? config('app.timezone', 'America/Guayaquil');
                    $when = Carbon::parse($appointment->start_time)->timezone($tz)->format('d/m/Y a las H:i');

                    $appointment->update(['status' => 'cancelled']);

                    $gCalDeleted = $this->calendarService->deleteAppointment($appointment);

                    return json_encode([
                        'status' => 'success',
                        'message' => "La cita del {$when} ha sido cancelada exitosamente y el horario quedó liberado.",
                        'google_calendar_deleted' => $gCalDeleted,
                    ], JSON_UNESCAPED_UNICODE);
                } catch (\Exception $e) {
                    Log::error('Error en cancel_appointment: '.$e->getMessage());

                    return json_encode(['status' => 'error', 'message' => 'Error interno al cancelar la cita.'], JSON_UNESCAPED_UNICODE);
                }
            });
    }

    /**
     * 5. Tool: search_services
     */
    protected function searchServicesTool(): Tool
    {
        return (new Tool)
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
                            'services' => $allServices->toArray(),
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    return json_encode([
                        'status' => 'success',
                        'services' => $services->toArray(),
                    ], JSON_UNESCAPED_UNICODE);
                } catch (\Exception $e) {
                    Log::error('Error en search_services: '.$e->getMessage());

                    return json_encode(['status' => 'error', 'message' => 'Error al consultar servicios.'], JSON_UNESCAPED_UNICODE);
                }
            });
    }

    /**
     * 6. Tool: generate_payment_link (para enviar link de abono por WhatsApp)
     */
    protected function generatePaymentLinkTool(?Contact $contact = null): Tool
    {
        return (new Tool)
            ->as('generate_payment_link')
            ->for('Genera un enlace de pago/abono para que el paciente reserve su cita o pague un servicio.')
            ->withNumberParameter('amount', 'Monto en USD del abono o pago', true)
            ->withStringParameter('description', 'Descripción del concepto del pago', false)
            ->using(function (float $amount, string $description = 'Abono de Reserva de Cita') use ($contact) {
                try {
                    $res = $this->paymentService->generatePaymentLink($contact, $amount, $description);

                    return json_encode($res, JSON_UNESCAPED_UNICODE);
                } catch (\Exception $e) {
                    Log::error('Error en generate_payment_link: '.$e->getMessage());

                    return json_encode(['status' => 'error', 'message' => 'Error al generar enlace de pago.'], JSON_UNESCAPED_UNICODE);
                }
            });
    }

    /**
     * 7. Tool: transfer_to_human
     */
    protected function transferToHumanTool(?Contact $contact = null): Tool
    {
        return (new Tool)
            ->as('transfer_to_human')
            ->for('Pausa la IA y transfiere la conversación al personal médico/recepcionista humano cuando el cliente lo pida o haya una urgencia.')
            ->withStringParameter('reason', 'Motivo de la transferencia o urgencia expresada por el paciente', true)
            ->using(function (string $reason) use ($contact) {
                try {
                    $business = $this->getActiveBusiness($contact);
                    $tz = $business?->timezone ?? config('app.timezone', 'America/Guayaquil');
                    if ($contact) {
                        $contact->update(['bot_paused_until' => Carbon::now($tz)->addHours(24)]);
                        Log::info("Bot pausado por 24h para paciente {$contact->phone_number}. Motivo: {$reason}");
                    }

                    $now = Carbon::now($tz)->format('d/m/Y H:i');
                    $businessName = $business?->name ?? 'Consultorio';
                    $staffMessage = "🔔 <b>[{$businessName}] Paciente requiere atención humana</b>\n"
                        ."👤 Paciente: {$contact->name}\n"
                        ."📱 WhatsApp: {$contact->phone_number}\n"
                        ."📝 Motivo: {$reason}\n"
                        ."🕒 {$now}\n"
                        .'⏸️ El bot quedó en pausa 24h para este paciente.';

                    $this->telegramService->notifyStaff($staffMessage, $business?->telegram_chat_id);

                    return json_encode([
                        'status' => 'success',
                        'message' => 'El bot ha sido pausado. Informa amablemente al paciente que la recepcionista del consultorio se pondrá en contacto con él a la brevedad.',
                    ], JSON_UNESCAPED_UNICODE);
                } catch (\Exception $e) {
                    Log::error('Error en transfer_to_human: '.$e->getMessage());

                    return json_encode(['status' => 'error', 'message' => 'Error al transferir a asesor humano.'], JSON_UNESCAPED_UNICODE);
                }
            });
    }
}
