<?php

namespace App\Services\AdminTools;

use App\Models\Business;
use App\Models\User;
use App\Services\BusinessContext;
use Prism\Prism\Tool;

class AdminConfigTools
{
    /**
     * Get array of Prism tools for business configuration.
     *
     * @return array<int, Tool>
     */
    public function getTools(?User $user = null): array
    {
        return [
            $this->updateAiPersonaTool($user),
            $this->updateBusinessHoursTool($user),
            $this->updateWorkingDaysTool($user),
        ];
    }

    /**
     * Tool: update_ai_persona (Owner only, behind ApprovalGate)
     */
    public function updateAiPersonaTool(?User $user = null): Tool
    {
        return AdminTool::make(
            'update_ai_persona',
            'Modifica la personalidad, tono o instrucciones de comportamiento personalizadas del asistente Ualdo.'
        )
            ->withRoles(['owner'])
            ->requiresApproval(true)
            ->withStringParameter('persona_description', 'Descripción detallada del tono o personalidad deseada (ej. Sé formal, habla de usted)', true)
            ->using(function (string $persona_description) {
                /** @var Business|null $business */
                $business = BusinessContext::get();
                if (! $business) {
                    return json_encode(['status' => 'error', 'message' => 'No hay negocio activo en contexto.'], JSON_UNESCAPED_UNICODE);
                }

                $cleanPersona = mb_substr(trim($persona_description), 0, 300);
                $settings = $business->settings ?? [];
                $settings['ai_persona'] = $cleanPersona;

                $business->update(['settings' => $settings]);

                return json_encode([
                    'status' => 'success',
                    'message' => 'La personalidad del asistente ha sido actualizada exitosamente.',
                    'ai_persona' => $cleanPersona,
                ], JSON_UNESCAPED_UNICODE);
            })
            ->build($user);
    }

    /**
     * Tool: update_business_hours (Owner only, behind ApprovalGate, validates start < end)
     */
    public function updateBusinessHoursTool(?User $user = null): Tool
    {
        return AdminTool::make(
            'update_business_hours',
            'Modifica los horarios comercial de apertura y cierre de atención del negocio.'
        )
            ->withRoles(['owner'])
            ->requiresApproval(true)
            ->withStringParameter('start_time', 'Hora de apertura en formato HH:MM (ej. 09:00)', true)
            ->withStringParameter('end_time', 'Hora de cierre en formato HH:MM (ej. 18:00)', true)
            ->using(function (string $start_time, string $end_time) {
                /** @var Business|null $business */
                $business = BusinessContext::get();
                if (! $business) {
                    return json_encode(['status' => 'error', 'message' => 'No hay negocio activo en contexto.'], JSON_UNESCAPED_UNICODE);
                }

                if ($start_time >= $end_time) {
                    return json_encode([
                        'status' => 'error',
                        'message' => "Horario inválido: La hora de inicio ({$start_time}) debe ser anterior a la hora de cierre ({$end_time}).",
                    ], JSON_UNESCAPED_UNICODE);
                }

                $business->update([
                    'business_hours_start' => $start_time,
                    'business_hours_end' => $end_time,
                ]);

                return json_encode([
                    'status' => 'success',
                    'message' => "Horario comercial actualizado a {$start_time} - {$end_time}.",
                ], JSON_UNESCAPED_UNICODE);
            })
            ->build($user);
    }

    /**
     * Tool: update_working_days (Owner only, behind ApprovalGate)
     */
    public function updateWorkingDaysTool(?User $user = null): Tool
    {
        return AdminTool::make(
            'update_working_days',
            'Configura los días laborables de atención del negocio.'
        )
            ->withRoles(['owner'])
            ->requiresApproval(true)
            ->withArrayParameter('days', 'Lista de días laborables en inglés (monday, tuesday, wednesday, thursday, friday, saturday, sunday)', 'string', true)
            ->using(function (array $days) {
                /** @var Business|null $business */
                $business = BusinessContext::get();
                if (! $business) {
                    return json_encode(['status' => 'error', 'message' => 'No hay negocio activo en contexto.'], JSON_UNESCAPED_UNICODE);
                }

                $validDays = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];
                $cleanDays = array_values(array_intersect(array_map('strtolower', $days), $validDays));

                if (empty($cleanDays)) {
                    return json_encode([
                        'status' => 'error',
                        'message' => 'Debes proporcionar al menos un día laborable válido.',
                    ], JSON_UNESCAPED_UNICODE);
                }

                $business->update(['working_days' => $cleanDays]);

                return json_encode([
                    'status' => 'success',
                    'message' => 'Días laborables del negocio actualizados exitosamente.',
                    'working_days' => $cleanDays,
                ], JSON_UNESCAPED_UNICODE);
            })
            ->build($user);
    }
}
