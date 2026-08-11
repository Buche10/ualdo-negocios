<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationMapping extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'provider',
        'external_id',
        'external_uid',
        'inventory_item_id',
        'external_hash',
    ];

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
