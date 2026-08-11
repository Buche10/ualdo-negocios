<?php

namespace App\Models;

use Cknow\Money\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'inventory_item_id',
        'name',
        'unit_price_cents',
        'quantity',
        'line_total_cents',
    ];

    protected $casts = [
        'unit_price_cents' => 'integer',
        'quantity' => 'integer',
        'line_total_cents' => 'integer',
    ];

    public function getUnitPriceAttribute(): Money
    {
        return Money::USD($this->unit_price_cents);
    }

    public function getLineTotalAttribute(): Money
    {
        return Money::USD($this->line_total_cents);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class);
    }
}
