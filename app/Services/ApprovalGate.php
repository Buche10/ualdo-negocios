<?php

namespace App\Services;

use App\Models\Business;
use App\Models\PendingAction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Str;

class ApprovalGate
{
    /**
     * Create a single-use pending action requiring user approval.
     */
    public function createPendingAction(User $user, Business $business, string $action, array $payload, int $ttlMinutes = 15): PendingAction
    {
        return BusinessContext::runInContext($business, function () use ($user, $action, $payload, $ttlMinutes) {
            $token = Str::random(32);

            return PendingAction::create([
                'user_id' => $user->id,
                'action' => $action,
                'payload' => $payload,
                'token' => $token,
                'expires_at' => Carbon::now()->addMinutes($ttlMinutes),
                'status' => 'pending',
            ]);
        });
    }

    /**
     * Confirm and execute a pending action by its token using atomic conditional update.
     */
    public function confirmAction(string $token, User $user, callable $executor): array
    {
        // 1. Atomic conditional update claiming the pending action token
        $claimedCount = PendingAction::withoutGlobalScopes()
            ->where('token', $token)
            ->where('user_id', $user->id)
            ->where('business_id', $user->business_id)
            ->where('status', 'pending')
            ->where('expires_at', '>', Carbon::now())
            ->update(['status' => 'confirmed']);

        if ($claimedCount === 0) {
            /** @var PendingAction|null $pending */
            $pending = PendingAction::withoutGlobalScopes()->where('token', $token)->first();

            if (! $pending) {
                return [
                    'success' => false,
                    'message' => 'El token de aprobación no existe o es inválido.',
                ];
            }

            if ($pending->user_id !== $user->id) {
                return [
                    'success' => false,
                    'message' => 'No tienes autorización para confirmar esta acción.',
                ];
            }

            /** @var Business|null $business */
            $business = $pending->business;
            if (! $business || $business->id !== $user->business_id) {
                return [
                    'success' => false,
                    'message' => 'La acción solicitada no pertenece a tu negocio.',
                ];
            }

            if (Carbon::now()->gt($pending->expires_at)) {
                $pending->update(['status' => 'expired']);

                return [
                    'success' => false,
                    'message' => 'El token de aprobación ha expirado. Por favor solicita la acción nuevamente.',
                ];
            }

            return [
                'success' => false,
                'message' => "La acción ya fue procesada anteriormente (Estado: {$pending->status}).",
            ];
        }

        /** @var PendingAction $pending */
        $pending = PendingAction::withoutGlobalScopes()->where('token', $token)->firstOrFail();
        /** @var Business $business */
        $business = $pending->business;

        return BusinessContext::runInContext($business, function () use ($pending, $user, $executor) {
            $result = $executor($pending->action, $pending->payload, $user);

            app(AuditService::class)->log(
                action: "confirmed:{$pending->action}",
                oldValues: null,
                newValues: ['payload' => $pending->payload, 'result' => $result],
                user: $user
            );

            return [
                'success' => true,
                'message' => 'Acción confirmada y ejecutada exitosamente.',
                'result' => $result,
            ];
        });
    }

    /**
     * Cancel a pending action.
     */
    public function cancelAction(string $token, User $user): bool
    {
        /** @var PendingAction|null $pending */
        $pending = PendingAction::withoutGlobalScopes()->where('token', $token)->first();

        if (! $pending || $pending->user_id !== $user->id) {
            return false;
        }

        if ($pending->status === 'pending') {
            $pending->update(['status' => 'cancelled']);

            app(AuditService::class)->log(
                action: "cancelled:{$pending->action}",
                user: $user
            );

            return true;
        }

        return false;
    }
}
