<?php

namespace App\Traits;

use App\Models\Business;
use App\Scopes\BusinessScope;
use App\Services\BusinessContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

trait BelongsToBusiness
{
    /**
     * Boot the trait to attach global scope and auto-fill business_id on creation.
     */
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope(new BusinessScope);

        static::creating(function ($model) {
            if (empty($model->business_id)) {
                $tenantId = BusinessContext::getTenantId();
                if ($tenantId !== null) {
                    $model->business_id = $tenantId;
                } else {
                    throw new RuntimeException('Cannot create tenant model ['.get_class($model).'] without an active BusinessContext or explicit business_id.');
                }
            }
        });
    }

    /**
     * Relationship: Belongs to Business
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
