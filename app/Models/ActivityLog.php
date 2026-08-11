<?php

namespace App\Models;

use App\Scopes\BusinessScope;
use App\Services\BusinessContext;
use App\Traits\BelongsToBusiness;
use Illuminate\Support\Facades\Log;
use Spatie\Activitylog\Models\Activity;

class ActivityLog extends Activity
{
    use BelongsToBusiness;

    protected $table = 'activity_log';

    protected $fillable = [
        'business_id',
        'log_name',
        'description',
        'subject_type',
        'event',
        'subject_id',
        'causer_type',
        'causer_id',
        'properties',
        'batch_uuid',
    ];

    protected static function bootBelongsToBusiness(): void
    {
        static::creating(function ($model) {
            if (empty($model->business_id)) {
                $tenantId = BusinessContext::getTenantId();

                if (! $tenantId) {
                    $subject = $model->subject;
                    if ($subject && isset($subject->business_id)) {
                        $tenantId = $subject->business_id;
                    } elseif ($subject instanceof Business) {
                        $tenantId = $subject->id;
                    }
                }

                if (! $tenantId) {
                    $causer = $model->causer;
                    if ($causer && isset($causer->business_id)) {
                        $tenantId = $causer->business_id;
                    }
                }

                if (! $tenantId && auth()->check() && auth()->user()?->business_id) {
                    $tenantId = auth()->user()->business_id;
                }

                if ($tenantId) {
                    $model->business_id = $tenantId;
                }
            }

            if (empty($model->business_id)) {
                Log::warning('ActivityLog creado sin business_id en contexto.', [
                    'description' => $model->description,
                    'subject_type' => $model->subject_type,
                    'subject_id' => $model->subject_id,
                ]);
            }
        });

        static::addGlobalScope(new BusinessScope);
    }
}
