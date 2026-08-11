<?php

namespace App\Services\AdminTools;

use App\Models\Appointment;
use App\Models\Task;
use App\Models\User;
use App\Services\BusinessContext;
use App\Services\TelegramService;
use Carbon\Carbon;
use Prism\Prism\Tool;

class AdminCrossSkillTools
{
    /**
     * Get array of Prism tools for cross-skill tasks and stats.
     *
     * @return array<int, Tool>
     */
    public function getTools(?User $user = null): array
    {
        return [
            $this->assignTaskTool($user),
            $this->getAgendaStatsTool($user),
        ];
    }

    /**
     * Tool: assign_task (Reuses Task model + proactive Telegram DM)
     */
    public function assignTaskTool(?User $user = null): Tool
    {
        return AdminTool::make(
            'assign_task',
            'Asigna una nueva tarea administrativa o de atención a un miembro del equipo del negocio.'
        )
            ->withStringParameter('staff_name', 'Nombre o email del usuario/colaborador al que se le asigna la tarea', true)
            ->withStringParameter('title', 'Título o descripción breve de la tarea', true)
            ->withStringParameter('due_date', 'Fecha límite en formato YYYY-MM-DD (opcional)', false)
            ->using(function (string $staff_name = '', string $title = '', ?string $due_date = null) use ($user) {
                $business = BusinessContext::get();
                if (! $business) {
                    return json_encode(['status' => 'error', 'message' => 'No hay negocio activo en contexto.'], JSON_UNESCAPED_UNICODE);
                }

                if (empty(trim($staff_name)) || empty(trim($title))) {
                    return json_encode(['status' => 'error', 'message' => 'El nombre del colaborador y el título de la tarea son obligatorios.'], JSON_UNESCAPED_UNICODE);
                }

                // Match staff in current business
                $assignee = User::where('business_id', $business->id)
                    ->where(function ($q) use ($staff_name) {
                        $q->where('name', 'like', "%{$staff_name}%")
                            ->orWhere('email', 'like', "%{$staff_name}%");
                    })
                    ->first();

                if (! $assignee) {
                    return json_encode([
                        'status' => 'error',
                        'message' => "No se encontró ningún miembro del equipo con el nombre o correo '{$staff_name}' en tu negocio.",
                    ], JSON_UNESCAPED_UNICODE);
                }

                $dueAt = null;
                if (! empty($due_date)) {
                    $dueAt = Carbon::parse($due_date);
                }

                $task = Task::create([
                    'business_id' => $business->id,
                    'assigned_to' => $assignee->id,
                    'created_by' => $user?->id,
                    'title' => trim($title),
                    'description' => "Tarea asignada desde el Chat Administrativo por {$user?->name}",
                    'due_at' => $dueAt,
                    'status' => 'pending',
                ]);

                // Proactive DM notification via Telegram if telegram_chat_id is present
                if (! empty($assignee->telegram_chat_id)) {
                    $telegramService = app(TelegramService::class);
                    $msg = "📋 <b>Nueva Tarea Asignada</b>\n"
                        ."📌 Título: {$task->title}\n"
                        ."👤 Asignado por: {$user?->name}\n"
                        .($dueAt ? "🕒 Fecha Límite: {$dueAt->format('d/m/Y')}\n" : '');
                    $telegramService->notifyStaff($msg, $assignee->telegram_chat_id);
                }

                return json_encode([
                    'status' => 'success',
                    'message' => "Tarea '{$task->title}' asignada a {$assignee->name} exitosamente.",
                    'task_id' => $task->id,
                    'assignee' => $assignee->name,
                ], JSON_UNESCAPED_UNICODE);
            })
            ->build($user);
    }

    /**
     * Tool: get_agenda_stats
     */
    public function getAgendaStatsTool(?User $user = null): Tool
    {
        return AdminTool::make(
            'get_agenda_stats',
            'Obtiene estadísticas y métricas agregadas de la agenda de citas del negocio (citas totales, agendadas, canceladas, ocupación).'
        )
            ->withStringParameter('period', 'Periodo a consultar: today, week, month (opcional, defecto: week)', false)
            ->using(function (?string $period = 'week') {
                $business = BusinessContext::get();
                if (! $business) {
                    return json_encode(['status' => 'error', 'message' => 'No hay negocio activo en contexto.'], JSON_UNESCAPED_UNICODE);
                }

                $tz = $business->timezone ?? config('app.timezone', 'America/Guayaquil');
                $now = Carbon::now($tz);

                [$startDate, $endDate] = match ($period) {
                    'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
                    'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()],
                    default => [$now->copy()->startOfWeek(), $now->copy()->endOfWeek()],
                };

                $query = Appointment::where('business_id', $business->id)
                    ->whereBetween('start_time', [$startDate, $endDate]);

                $total = (clone $query)->count();
                $scheduled = (clone $query)->where('status', 'scheduled')->count();
                $completed = (clone $query)->where('status', 'completed')->count();
                $cancelled = (clone $query)->where('status', 'cancelled')->count();

                return json_encode([
                    'status' => 'success',
                    'period' => $period,
                    'start_date' => $startDate->format('Y-m-d'),
                    'end_date' => $endDate->format('Y-m-d'),
                    'metrics' => [
                        'total_appointments' => $total,
                        'scheduled' => $scheduled,
                        'completed' => $completed,
                        'cancelled' => $cancelled,
                        'completion_rate_percentage' => $total > 0 ? round(($completed / $total) * 100, 1) : 0,
                    ],
                ], JSON_UNESCAPED_UNICODE);
            })
            ->build($user);
    }
}
