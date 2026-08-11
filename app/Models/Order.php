<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Cknow\Money\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Order extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'contact_id',
        'status',
        'payment_method',
        'payment_status',
        'subtotal_cents',
        'total_cents',
        'notes',
        'customer_info',
        'placed_at',
    ];

    protected $casts = [
        'subtotal_cents' => 'integer',
        'total_cents' => 'integer',
        'customer_info' => 'array',
        'placed_at' => 'datetime',
    ];

    public function getSubtotalAttribute(): Money
    {
        return Money::USD($this->subtotal_cents);
    }

    public function setSubtotalAttribute(mixed $value): void
    {
        if ($value instanceof Money) {
            $this->attributes['subtotal_cents'] = $value->getAmount();
        } else {
            $this->attributes['subtotal_cents'] = (int) round(((float) $value) * 100);
        }
    }

    public function getTotalAttribute(): Money
    {
        return Money::USD($this->total_cents);
    }

    public function setTotalAttribute(mixed $value): void
    {
        if ($value instanceof Money) {
            $this->attributes['total_cents'] = $value->getAmount();
        } else {
            $this->attributes['total_cents'] = (int) round(((float) $value) * 100);
        }
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }
}
