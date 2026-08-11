<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Contact;
use App\Models\Doctor;
use App\Models\InventoryItem;
use Carbon\Carbon;
use Cknow\Money\Money;
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
        /** @var Business|null $contactBusiness */
        $contactBusiness = $contact ? $contact->business : null;
        /** @var Business|null $business */
        $business = BusinessContext::get() ?? $contactBusiness ?? Business::first();

        return $business;
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
     * 1. Tool: check_availability (optimizado con eager loading para evitar N+1)
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

                    $query = Appointment::with('doctor')
                        ->whereBetween('start_time', [$dayStart, $dayEnd])
                        ->where('status', '!=', 'cancelled');

                    if ($doctor_name) {
                        $doc = Doctor::where('name', 'like', "%{$doctor_name}%")->first();
                        if ($doc) {
                            $query->where('doctor_id', $doc->id);
                        }
                    }

                    $appointments = $query->get()
                        ->map(function (Appointment $app) use ($tz) {
                            /** @var Doctor|null $doc */
                            $doc = $app->doctor;

                            return [
                                'from' => Carbon::parse($app->start_time)->timezone($tz)->format('H:i'),
                                'to' => Carbon::parse($app->end_time)->timezone($tz)->format('H:i'),
                                'doctor_id' => $app->doctor_id,
                                'doctor_name' => $doc ? $doc->name : null,
                            ];
                        })
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
     * 2. Tool: schedule_appointment (con validación de doctor no encontrado)
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

                    // A2 Guard: Validar fecha futura
                    if ($startTime->lt(Carbon::now($tz))) {
                        return json_encode([
                            'status' => 'error',
                            'message' => 'No se pueden agendar citas en fechas o horas pasadas. Por favor solicita al paciente que elija una fecha y hora futura.',
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    // A2 Guard: Validar días laborables
                    $workingDays = $business?->working_days;
                    if (is_array($workingDays) && ! empty($workingDays)) {
                        $dayOfWeek = strtolower($startTime->format('l'));
                        if (! in_array($dayOfWeek, array_map('strtolower', $workingDays))) {
                            return json_encode([
                                'status' => 'error',
                                'message' => 'El consultorio no atiende en el día de la semana solicitado. Por favor solicita al paciente que seleccione otro día de atención.',
                            ], JSON_UNESCAPED_UNICODE);
                        }
                    }

                    // A2 Guard: Validar horario comercial
                    $hoursStart = $business?->business_hours_start ?? '09:00';
                    $hoursEnd = $business?->business_hours_end ?? '18:00';
                    $timeStr = $startTime->format('H:i');
                    $endTimeStr = $endTime->format('H:i');
                    if ($timeStr < $hoursStart || $endTimeStr > $hoursEnd) {
                        return json_encode([
                            'status' => 'error',
                            'message' => "El horario solicitado está fuera de nuestro horario de atención ({$hoursStart} a {$hoursEnd}).",
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    // Resolver doctor específico o rechazar si el doctor especificado no existe
                    $doctor = null;
                    if (! empty($doctor_name)) {
                        $doctor = Doctor::where('name', 'like', "%{$doctor_name}%")->first();
                        if (! $doctor) {
                            return json_encode([
                                'status' => 'error',
                                'message' => "No se encontró al especialista '{$doctor_name}' en el consultorio. Por favor verifica los doctores disponibles.",
                            ], JSON_UNESCAPED_UNICODE);
                        }
                    } else {
                        $activeDoctors = Doctor::where('is_active', true)->get();
                        if ($activeDoctors->isNotEmpty()) {
                            foreach ($activeDoctors as $actDoc) {
                                $hasOverlap = Appointment::where('doctor_id', $actDoc->id)
                                    ->where('status', '!=', 'cancelled')
                                    ->where('start_time', '<', $endTime)
                                    ->where('end_time', '>', $startTime)
                                    ->exists();
                                if (! $hasOverlap) {
                                    $doctor = $actDoc;
                                    break;
                                }
                            }
                            if (! $doctor) {
                                return json_encode([
                                    'status' => 'error',
                                    'message' => 'Todos los doctores del consultorio están ocupados en el horario solicitado. Por favor elige otro horario.',
                                ], JSON_UNESCAPED_UNICODE);
                            }
                        }
                    }

                    /** @var Appointment|null $appointment */
                    $appointment = DB::transaction(function () use ($startTime, $endTime, $title, $doctor, $contact) {
                        $query = Appointment::where('status', '!=', 'cancelled')
                            ->where('start_time', '<', $endTime)
                            ->where('end_time', '>', $startTime);

                        if ($doctor) {
                            $query->where('doctor_id', $doctor->id);
                        } else {
                            $query->whereNull('doctor_id');
                        }

                        if ($query->lockForUpdate()->exists()) {
                            return null;
                        }

                        $contactId = $contact ? $contact->id : null;
                        $contactName = $contact ? $contact->name : 'Cliente';
                        $contactPhone = $contact ? $contact->phone_number : '';

                        $app = Appointment::create([
                            'contact_id' => $contactId,
                            'doctor_id' => $doctor?->id,
                            'title' => $title,
                            'description' => "Cita agendada vía WhatsApp para el paciente {$contactName} ({$contactPhone})",
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                            'status' => 'scheduled',
                            'customer_info' => [
                                'patient_name' => $contactName,
                                'phone' => $contactPhone,
                                'doctor_name' => $doctor?->name,
                            ],
                        ]);

                        // Descuento de insumos de servicio vía InventoryService (ledger)
                        $serviceItem = InventoryItem::services()->where('name', 'like', "%{$title}%")->first();
                        if ($serviceItem) {
                            $inventoryService = app(InventoryService::class);
                            foreach ($serviceItem->requiredSupplies as $supply) {
                                $pivot = $supply->getAttribute('pivot');
                                $qty = $pivot ? (int) $pivot->quantity_required : 1;
                                if ($supply->stock >= $qty) {
                                    $inventoryService->adjustStock(
                                        item: $supply,
                                        delta: -$qty,
                                        type: 'out',
                                        reason: "Consumo por cita #{$app->id}",
                                        source: $app
                                    );
                                    Log::info("Descontado {$qty} unidades de {$supply->name} para la cita {$app->id}");
                                } else {
                                    Log::warning("Stock insuficiente para insumo {$supply->name} al agendar cita #{$app->id}");
                                }
                            }
                        }

                        return $app;
                    });

                    if (! $appointment) {
                        return json_encode([
                            'status' => 'error',
                            'message' => 'El horario solicitado se solapa con otra cita existente en el consultorio. Por favor pide al paciente que elija un horario diferente.',
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    // Sincronizar Google Calendar FUERA de la transacción DB
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

        return [$upcoming->first(), null];
    }

    /**
     * 3. Tool: reschedule_appointment
     */
    protected function rescheduleAppointmentTool(?Contact $contact = null): Tool
    {
        return (new Tool)
            ->as('reschedule_appointment')
            ->for('Reprograma o cambia la fecha/hora de una cita futura existente del paciente.')
            ->withStringParameter('new_datetime', 'Nueva fecha y hora en formato YYYY-MM-DD HH:MM', true)
            ->withStringParameter('current_datetime', 'Fecha y hora actual de la cita a mover en formato YYYY-MM-DD HH:MM (opcional si solo tiene 1 cita)', false)
            ->using(function (string $new_datetime, ?string $current_datetime = null) use ($contact) {
                try {
                    [$appointment, $errorJson] = $this->resolvePatientAppointment($contact, $current_datetime);
                    if ($errorJson) {
                        return $errorJson;
                    }

                    $business = $this->getActiveBusiness($contact);
                    $tz = $business?->timezone ?? config('app.timezone', 'America/Guayaquil');
                    $slotDuration = $business?->slot_duration_minutes ?? 45;

                    $newStart = Carbon::parse($new_datetime, $tz);
                    $newEnd = $newStart->copy()->addMinutes($slotDuration);

                    if ($newStart->lt(Carbon::now($tz))) {
                        return json_encode([
                            'status' => 'error',
                            'message' => 'No se puede reprogramar a una fecha u hora pasada.',
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    $overlap = Appointment::where('id', '!=', $appointment->id)
                        ->where('status', '!=', 'cancelled')
                        ->where('start_time', '<', $newEnd)
                        ->where('end_time', '>', $newStart);

                    if ($appointment->doctor_id) {
                        $overlap->where('doctor_id', $appointment->doctor_id);
                    } else {
                        $overlap->whereNull('doctor_id');
                    }

                    if ($overlap->exists()) {
                        return json_encode([
                            'status' => 'error',
                            'message' => 'El nuevo horario solicitado ya está ocupado. Pide al paciente que elija otra hora.',
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    $appointment->update([
                        'start_time' => $newStart,
                        'end_time' => $newEnd,
                    ]);

                    $this->calendarService->updateAppointment($appointment);

                    return json_encode([
                        'status' => 'success',
                        'message' => "Cita reprogramada con éxito para el {$newStart->format('d/m/Y a las H:i')} (Zona horaria {$tz}).",
                    ], JSON_UNESCAPED_UNICODE);
                } catch (\Exception $e) {
                    Log::error('Error en reschedule_appointment: '.$e->getMessage());

                    return json_encode(['status' => 'error', 'message' => 'Error al reprogramar cita.'], JSON_UNESCAPED_UNICODE);
                }
            });
    }

    /**
     * 4. Tool: cancel_appointment (cancela en DB y borra el evento en Google Calendar si existe)
     */
    protected function cancelAppointmentTool(?Contact $contact = null): Tool
    {
        return (new Tool)
            ->as('cancel_appointment')
            ->for('Cancela una cita futura programada del paciente y libera el horario.')
            ->withStringParameter('datetime', 'Fecha y hora de la cita a cancelar en formato YYYY-MM-DD HH:MM (opcional)', false)
            ->using(function (?string $datetime = null) use ($contact) {
                try {
                    [$appointment, $errorJson] = $this->resolvePatientAppointment($contact, $datetime);
                    if ($errorJson) {
                        return $errorJson;
                    }

                    $appointment->update(['status' => 'cancelled']);

                    // Borrar evento correspondiente de Google Calendar si estaba sincronizado
                    if (! empty($appointment->google_event_id)) {
                        $this->calendarService->deleteAppointment($appointment);
                    }

                    return json_encode([
                        'status' => 'success',
                        'message' => 'La cita ha sido cancelada exitosamente.',
                    ], JSON_UNESCAPED_UNICODE);
                } catch (\Exception $e) {
                    Log::error('Error en cancel_appointment: '.$e->getMessage());

                    return json_encode(['status' => 'error', 'message' => 'Error al cancelar la cita.'], JSON_UNESCAPED_UNICODE);
                }
            });
    }

    /**
     * 5. Tool: search_services (para consultar catálogo de servicios e insumos)
     */
    protected function searchServicesTool(): Tool
    {
        return (new Tool)
            ->as('search_services')
            ->for('Busca servicios, tratamientos, procedimientos médicos u odontológicos y sus precios en el catálogo.')
            ->withStringParameter('query', 'Término de búsqueda o nombre del tratamiento/servicio', true)
            ->using(function (string $query) {
                try {
                    $services = InventoryItem::services()
                        ->where(function ($q) use ($query) {
                            $q->where('name', 'like', "%{$query}%")
                                ->orWhere('description', 'like', "%{$query}%");
                        })
                        ->get();

                    if ($services->isEmpty()) {
                        $allServices = InventoryItem::services()->get();

                        return json_encode([
                            'status' => 'success',
                            'message' => "No se encontró coincidencia directa para '{$query}', pero aquí tienes la lista general de servicios del consultorio:",
                            'services' => $allServices->map(fn (InventoryItem $i) => $i->toLlmArray())->values()->toArray(),
                        ], JSON_UNESCAPED_UNICODE);
                    }

                    return json_encode([
                        'status' => 'success',
                        'services' => $services->map(fn (InventoryItem $i) => $i->toLlmArray())->values()->toArray(),
                    ], JSON_UNESCAPED_UNICODE);
                } catch (\Exception $e) {
                    Log::error('Error en search_services: '.$e->getMessage());

                    return json_encode(['status' => 'error', 'message' => 'Error al consultar servicios.'], JSON_UNESCAPED_UNICODE);
                }
            });
    }

    /**
     * 6. Tool: generate_payment_link (con resolución de monto por catálogo o sugerencia validada)
     */
    protected function generatePaymentLinkTool(?Contact $contact = null): Tool
    {
        return (new Tool)
            ->as('generate_payment_link')
            ->for('Genera un enlace de pago/abono online o registra instrucción de pago presencial para reservar cita o pagar un servicio.')
            ->withNumberParameter('amount', 'Monto de referencia sugerido en USD', false)
            ->withStringParameter('description', 'Descripción del concepto del pago o servicio', false)
            ->withStringParameter('method', 'Modalidad de pago: online (pasarela) o presencial (pagar en local)', false)
            ->using(function (?float $amount = null, string $description = 'Abono de Reserva de Cita', ?string $method = 'online') use ($contact) {
                try {
                    $finalAmount = 20.0;
                    $serviceItem = InventoryItem::services()->where('name', 'like', "%{$description}%")->first();
                    $itemPrice = $serviceItem ? ($serviceItem->price instanceof Money ? ((int) $serviceItem->price->getAmount() / 100) : (float) $serviceItem->price) : 0.0;

                    if ($serviceItem && $itemPrice > 0) {
                        $finalAmount = $itemPrice;
                    } elseif ($amount && $amount > 0 && $amount <= 500.0) {
                        $finalAmount = (float) $amount;
                    }

                    $selectedMethod = in_array($method, ['online', 'presencial'], true) ? $method : 'online';
                    $res = $this->paymentService->generatePaymentLink($contact, $finalAmount, $description, $selectedMethod);

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
                    $contactName = $contact ? $contact->name : 'Desconocido';
                    $contactPhone = $contact ? $contact->phone_number : 'Sin número';

                    $staffMessage = "🔔 <b>[{$businessName}] Paciente requiere atención humana</b>\n"
                        ."👤 Paciente: {$contactName}\n"
                        ."📱 WhatsApp: {$contactPhone}\n"
                        ."📝 Motivo: {$reason}\n"
                        ."🕒 {$now}\n"
                        .'⏸️ El bot quedó en pausa 24h para este paciente.';

                    $this->telegramService->notifyStaff($staffMessage, $business?->telegram_chat_id);

                    return json_encode([
                        'status' => 'success',
                        'message' => 'He notificado al personal médico del consultorio. Un agente humano se comunicará contigo a la brevedad.',
                    ], JSON_UNESCAPED_UNICODE);
                } catch (\Exception $e) {
                    Log::error('Error en transfer_to_human: '.$e->getMessage());

                    return json_encode(['status' => 'error', 'message' => 'Error al transferir a un agente humano.'], JSON_UNESCAPED_UNICODE);
                }
            });
    }
}
