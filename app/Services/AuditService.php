<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Activity;

class AuditService
{
    /**
     * Log an audited action for the active business context using Spatie Activitylog.
     */
    public function log(
        string $action,
        ?Model $model = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?User $user = null,
        ?string $ipAddress = null
    ): ActivityLog {
        $currentUser = $user ?? auth()->user();
        $businessId = BusinessContext::getTenantId() ?? $currentUser?->business_id;

        $logger = activity()
            ->useLog('audit');

        if ($businessId) {
            $logger->tap(function (Activity $activity) use ($businessId) {
                if ($activity instanceof ActivityLog) {
                    $activity->business_id = $businessId;
                }
            });
        }

        if ($currentUser) {
            $logger->causedBy($currentUser);
        }

        if ($model) {
            $logger->performedOn($model);
        }

        $properties = array_filter([
            'old' => $oldValues,
            'new' => $newValues,
            'ip_address' => $ipAddress ?? (request()->hasHeader('User-Agent') ? request()->ip() : '127.0.0.1'),
        ]);

        if (! empty($properties)) {
            $logger->withProperties($properties);
        }

        /** @var ActivityLog $activity */
        $activity = $logger->log($action);

        return $activity;
    }
}
