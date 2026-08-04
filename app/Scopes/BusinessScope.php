<?php

namespace App\Scopes;

use App\Services\BusinessContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

class BusinessScope implements Scope
{
    /**
     * Apply the scope to a given Eloquent query builder.
     * Fail-closed strategy: if no tenant context is set and not in central mode, return 0 rows.
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = BusinessContext::getTenantId();

        if ($tenantId !== null) {
            $builder->where($model->getTable().'.business_id', '=', $tenantId);
        } elseif (BusinessContext::isCentral()) {
            // Central / Admin mode: query across all tenants allowed
            return;
        } else {
            // Fail-closed: block data retrieval when no tenant context or central override is active
            $builder->whereRaw('1 = 0');
        }
    }
}
