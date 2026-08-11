<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Cknow\Money\Money;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'payable_type',
        'payable_id',
        'contact_id',
        'provider',
        'method',
        'amount_cents',
        'currency',
        'status',
        'external_ref',
        'paid_at',
    ];

    protected $casts = [
        'amount_cents' => 'integer',
        'paid_at' => 'datetime',
    ];

    public function getAmountAttribute(): Money
    {
        return Money::USD($this->amount_cents);
    }

    public function setAmountAttribute(mixed $value): void
    {
        if ($value instanceof Money) {
            $this->attributes['amount_cents'] = $value->getAmount();
        } else {
            $this->attributes['amount_cents'] = (int) round(((float) $value) * 100);
        }
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }
}
