<?php

namespace App\Console\Commands;

use App\Models\StaffMemory;
use App\Models\Task;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SendTaskRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-task-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Envía recordatorios proactivos de tareas y memorias agendadas por Telegram.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->sendTaskReminders();
        $this->sendMemoryReminders();

        return Command::SUCCESS;
    }

    protected function sendTaskReminders(): void
    {
        $tasks = Task::withoutGlobalScopes()
            ->with(['assignee', 'business'])
            ->whereNull('reminder_sent_at')
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->where('status', 'pending')
            ->get();

        foreach ($tasks as $task) {
            $user = $task->assignee;
            if ($user && ! empty($user->telegram_chat_id)) {
                $msg = "⏰ <b>Recordatorio de Tarea Pendiente:</b>\n\"{$task->title}\"";
                if ($task->description) {
                    $msg .= "\n<i>{$task->description}</i>";
                }
                $this->sendTelegramMessage($user->telegram_chat_id, $msg);
            }
            $task->update(['reminder_sent_at' => now()]);
        }
    }

    protected function sendMemoryReminders(): void
    {
        $memories = StaffMemory::withoutGlobalScopes()
            ->with(['user', 'business'])
            ->where('type', 'reminder')
            ->whereNotNull('remind_at')
            ->where('remind_at', '<=', now())
            ->get();

        foreach ($memories as $memory) {
            $user = $memory->user;
            if ($user && ! empty($user->telegram_chat_id)) {
                $msg = "🧠 <b>Recordatorio Agendado de Ualdo:</b>\n\"{$memory->content}\"";
                $this->sendTelegramMessage($user->telegram_chat_id, $msg);
            }
            $memory->update(['type' => 'note']);
        }
    }

    protected function sendTelegramMessage(string $chatId, string $text): void
    {
        $botToken = config('services.telegram.bot_token');
        if (empty($botToken)) {
            return;
        }

        try {
            Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
            ]);
        } catch (\Exception $e) {
            Log::error('Error enviando recordatorio por Telegram: '.$e->getMessage());
        }
    }
}
