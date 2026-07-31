<?php

namespace App\Console\Commands;

use App\Models\Appointment;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
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
    public function handle(WhatsAppService $whatsapp)
    {
        $tomorrowStart = Carbon::tomorrow()->startOfDay();
        $tomorrowEnd = Carbon::tomorrow()->endOfDay();

        $appointments = Appointment::whereBetween('start_time', [$tomorrowStart, $tomorrowEnd])
            ->where('status', 'scheduled')
            ->with('contact')
            ->get();

        $this->info("Procesando " . $appointments->count() . " citas para recordatorio de mañana...");

        foreach ($appointments as $appointment) {
            $contact = $appointment->contact;
            if (!$contact || empty($contact->phone_number)) {
                continue;
            }

            $formattedTime = Carbon::parse($appointment->start_time)->format('H:i');
            $message = "Hola {$contact->name}, te recordamos tu cita de mañana a las {$formattedTime} en el consultorio. Motivo: {$appointment->title}. Si deseas cancelar o reprogramar, por favor responde a este mensaje.";

            $whatsapp->sendText($contact->phone_number, $message);
            Log::info("Recordatorio enviado a {$contact->phone_number} para la cita #{$appointment->id}");
        }

        $this->info("¡Recordatorios procesados con éxito!");

        return Command::SUCCESS;
    }
}
