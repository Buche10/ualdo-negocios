<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Models\Business;
use App\Services\BusinessContext;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class SendAppointmentRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'appointments:send-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envía recordatorios automáticos por WhatsApp 24 horas antes de las citas programadas.';

    /**
     * Execute the console command.
     */
    public function handle(WhatsAppService $whatsapp): int
    {
        /** @var Collection<int, Business> $businesses */
        $businesses = BusinessContext::runAsCentral(fn () => Business::all());

        foreach ($businesses as $business) {
            $tz = $business->timezone ?? (string) config('app.timezone', 'America/Guayaquil');
            $tomorrowStart = Carbon::tomorrow($tz)->startOfDay();
            $tomorrowEnd = Carbon::tomorrow($tz)->endOfDay();

            BusinessContext::runInContext($business, function () use ($business, $tomorrowStart, $tomorrowEnd, $tz, $whatsapp) {
                $appointments = Appointment::whereBetween('start_time', [$tomorrowStart, $tomorrowEnd])
                    ->where('status', 'scheduled')
                    ->whereNull('reminder_sent_at')
                    ->with('contact')
                    ->get();

                if ($appointments->isNotEmpty()) {
                    $this->info("Procesando {$appointments->count()} citas para '{$business->name}'...");

                    foreach ($appointments as $appointment) {
                        $contact = $appointment->contact;
                        if (! $contact || empty($contact->phone_number)) {
                            continue;
                        }

                        $formattedTime = Carbon::parse($appointment->start_time)->timezone($tz)->format('H:i');
                        $contactName = $contact->name ?? 'Cliente';
                        $businessName = $business->name ?? 'Consultorio';
                        $message = "Hola {$contactName}, te recordamos tu cita de mañana a las {$formattedTime} en {$businessName}. Motivo: {$appointment->title}. Si deseas cancelar o reprogramar, por favor responde a este mensaje.";

                        $whatsapp->sendText($contact->phone_number, $message);
                        $appointment->update(['reminder_sent_at' => Carbon::now($tz)]);
                        Log::info("Recordatorio enviado a {$contact->phone_number} para cita #{$appointment->id} [{$businessName}]");
                    }
                }
            });
        }

        $this->info('¡Recordatorios procesados con éxito!');

        return Command::SUCCESS;
    }
}
