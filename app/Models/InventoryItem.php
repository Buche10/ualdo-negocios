<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use HasFactory, BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'name',
        'description',
        'type',
        'price',
        'stock',
        'min_stock',
        'attributes',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'stock' => 'integer',
        'min_stock' => 'integer',
        'attributes' => 'array',
    ];

    public function requiredSupplies()
    {
        return $this->belongsToMany(
            InventoryItem::class,
            'service_supplies',
            'service_id',
            'supply_id'
        )->withPivot('quantity_required')->withTimestamps();
    }

    public function scopeServices($query)
    {
        return $query->where('type', 'service');
    }

    public function scopeSupplies($query)
    {
        return $query->where('type', '!=', 'service');
    }

    public function isLowStock(): bool
    {
        return $this->type !== 'service' && $this->stock <= $this->min_stock;
    }
}
