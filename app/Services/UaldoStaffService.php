<?php

namespace App\Services;

use App\Models\Business;
use App\Models\StaffMemory;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class UaldoStaffService
{
    /**
     * Procesa los mensajes recibidos del staff por Telegram y gestiona ruteo proactivo, tareas y memorias.
     */
    public function handleStaffMessage(User $user, string $text, string $chatId): void
    {
        /** @var Business|null $business */
        $business = $user->business;

        if ($business) {
            BusinessContext::set($business);
        }

        try {
            $cleanText = trim($text);
            $lowerText = strtolower($cleanText);

            // 1. Recall de memoria contextual ("¿qué te dije?", /memorias, /recordar)
            if ($lowerText === '/memorias' || $lowerText === '/recordar' || str_contains($lowerText, 'qué te dije') || str_contains($lowerText, 'mis notas')) {
                $this->handleRecall($user, $chatId);

                return;
            }

            // 2. Consultar tareas pendientes (/mis_tareas o "mis tareas")
            if ($lowerText === '/mis_tareas' || str_contains($lowerText, 'mis tareas') || $lowerText === 'tareas') {
                $this->handleListTasks($user, $chatId, $business?->name);

                return;
            }

            // 3. Crear nueva tarea con ruteo proactivo a terceros o por rol
            if (str_starts_with($lowerText, '/tarea') || str_contains($lowerText, 'crear tarea') || str_contains($lowerText, 'nueva tarea') || str_starts_with($lowerText, 'tarea')) {
                $this->handleCreateTask($user, $cleanText, $chatId, $business);

                return;
            }

            // 4. Memoria contextual y recordatorios agendados ("recordar", "nota", "guardar", "acuerdate")
            if (str_contains($lowerText, 'recordar') || str_contains($lowerText, 'nota') || str_contains($lowerText, 'acuerdate') || str_contains($lowerText, 'guardar')) {
                $this->handleSaveMemory($user, $cleanText, $chatId);

                return;
            }

            // 5. Ayuda por defecto
            $help = "👋 Hola <b>{$user->name}</b>. Soy Ualdo Staff. Puedo ayudarte a gestionar tu equipo:\n\n";
            $help .= "• <code>/tarea para Juan Revisar insumos</code> - Asignar tarea a un compañero con DM proactivo\n";
            $help .= "• <code>/tarea Comprar mascarillas</code> - Tarea auto-ruteada por rol\n";
            $help .= "• <code>/mis_tareas</code> - Ver tus tareas pendientes\n";
            $help .= "• <code>/memorias</code> o <i>\"¿Qué te dije?\"</i> - Recuperar notas de memoria\n";
            $help .= "• Escribe <i>\"Recordar mañana llamar a laboratorio\"</i> para agendar un aviso.\n";

            $this->sendMessage($chatId, $help);
        } finally {
            BusinessContext::forget();
        }
    }

    protected function handleRecall(User $user, string $chatId): void
    {
        $memories = StaffMemory::where('business_id', $user->business_id)
            ->where('user_id', $user->id)
            ->latest()
            ->take(10)
            ->get();

        if ($memories->isEmpty()) {
            $this->sendMessage($chatId, '🧠 No tienes memorias ni notas guardadas en Ualdo.');

            return;
        }

        $list = "🧠 <b>Tus memorias registradas en Ualdo:</b>\n\n";
        foreach ($memories as $idx => $m) {
            $num = $idx + 1;
            $timeStr = $m->remind_at ? " ⏰ (Aviso: {$m->remind_at->format('Y-m-d H:i')})" : '';
            $list .= "{$num}. {$m->content}{$timeStr}\n";
        }

        $this->sendMessage($chatId, $list);
    }

    protected function handleListTasks(User $user, string $chatId, ?string $businessName): void
    {
        $tasks = Task::where('business_id', $user->business_id)
            ->where('assigned_to', $user->id)
            ->where('status', 'pending')
            ->latest()
            ->take(10)
            ->get();

        if ($tasks->isEmpty()) {
            $this->sendMessage($chatId, '📋 No tienes tareas pendientes asignadas a ti en este momento.');

            return;
        }

        $name = $businessName ?? 'Ualdo';
        $list = "📋 <b>Tus tareas pendientes en {$name}:</b>\n\n";
        foreach ($tasks as $idx => $task) {
            $num = $idx + 1;
            $list .= "{$num}. {$task->title}\n";
        }

        $this->sendMessage($chatId, $list);
    }

    protected function handleCreateTask(User $user, string $cleanText, string $chatId, mixed $business): void
    {
        $titleRaw = preg_replace('/^\/tarea\s*|^crear tarea\s*|^nueva tarea\s*|^tarea\s*/i', '', $cleanText);
        $titleRaw = trim((string) $titleRaw);

        if (empty($titleRaw)) {
            $this->sendMessage($chatId, '⚠️ Especifique el título de la tarea. Ejemplo: `/tarea para Juan Comprar gasas`');

            return;
        }

        // Detectar si se asigna explícitamente a un miembro (ej. "para Juan ...", "a doctor ...")
        $assignedUser = null;
        if (preg_match('/^(?:para|a)\s+([a-záéíóúñ]+)\s+(.+)$/i', $titleRaw, $matches)) {
            $targetStr = strtolower($matches[1]);
            $title = trim($matches[2]);

            // Buscar miembro por nombre o rol
            $assignedUser = User::where('business_id', $user->business_id)
                ->where(function ($q) use ($targetStr) {
                    $q->where('name', 'LIKE', "%{$targetStr}%")
                        ->orWhere('role', $targetStr);
                })
                ->first();
        } else {
            $title = $titleRaw;
        }

        // Ruteo por rol automático si no se asignó explícitamente
        if (! $assignedUser) {
            $lowerTitle = strtolower($title);
            if (preg_match('/(stock|insumo|producto|material|guante|gasa|alcohol)/i', $lowerTitle)) {
                // Ruteo a recepcionista o dueño
                $assignedUser = User::where('business_id', $user->business_id)
                    ->whereIn('role', ['receptionist', 'owner'])
                    ->first();
            } elseif (preg_match('/(cita|paciente|tratamiento|consulta|doctor|medico)/i', $lowerTitle)) {
                // Ruteo a doctor o recepcionista
                $assignedUser = User::where('business_id', $user->business_id)
                    ->whereIn('role', ['doctor', 'receptionist'])
                    ->first();
            }
        }

        // Fallback al creador si no se encuentra otro usuario
        $targetUser = $assignedUser ?? $user;

        $task = Task::create([
            'business_id' => $user->business_id,
            'assigned_to' => $targetUser->id,
            'title' => $title,
            'status' => 'pending',
            'created_by' => $user->id,
        ]);

        // Responder al creador
        $this->sendMessage($chatId, "✅ <b>Tarea creada y asignada a {$targetUser->name}:</b>\n\"{$task->title}\"");

        // Notificación proactiva por DM en Telegram si se asignó a otro usuario que tenga telegram_chat_id
        if ($targetUser->id !== $user->id && ! empty($targetUser->telegram_chat_id)) {
            $dmText = "📌 <b>Nueva tarea asignada por {$user->name}:</b>\n\"{$task->title}\"";
            $this->sendMessage($targetUser->telegram_chat_id, $dmText);
        }
    }

    protected function handleSaveMemory(User $user, string $cleanText, string $chatId): void
    {
        $remindAt = $this->parseRemindDate($cleanText);
        $type = $remindAt ? 'reminder' : 'note';

        $memory = StaffMemory::create([
            'business_id' => $user->business_id,
            'user_id' => $user->id,
            'content' => $cleanText,
            'type' => $type,
            'remind_at' => $remindAt,
        ]);

        if ($remindAt) {
            $this->sendMessage($chatId, "⏰ <b>Recordatorio agendado para {$remindAt->format('d/m/Y H:i')}:</b>\n\"{$memory->content}\"");
        } else {
            $this->sendMessage($chatId, "🧠 <b>Ualdo lo recuerda:</b>\n\"{$memory->content}\"");
        }
    }

    protected function parseRemindDate(string $text): ?Carbon
    {
        $lower = strtolower($text);

        if (str_contains($lower, 'mañana')) {
            return now()->addDay()->setTime(9, 0, 0);
        }

        if (preg_match('/en (\d+)\s*(hora|horas|h)/i', $lower, $m)) {
            return now()->addHours((int) $m[1]);
        }

        if (preg_match('/en (\d+)\s*(minuto|minutos|min)/i', $lower, $m)) {
            return now()->addMinutes((int) $m[1]);
        }

        return null;
    }

    protected function sendMessage(string $chatId, string $text): void
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
            Log::error('Error enviando respuesta en UaldoStaffService: '.$e->getMessage());
        }
    }
}
