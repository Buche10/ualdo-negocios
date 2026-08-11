<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Cknow\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * @property int $id
 * @property int $business_id
 * @property string $name
 * @property string|null $description
 * @property string $type
 * @property Money $price
 * @property int $stock
 * @property int $min_stock
 * @property string|null $unit
 * @property-read Collection<int, InventoryItem> $requiredSupplies
 * @property mixed $pivot
 */
class InventoryItem extends Model
{
    use BelongsToBusiness, HasFactory, LogsActivity;

    protected $attributes = [
        'type' => 'service',
    ];

    protected $fillable = [
        'business_id',
        'name',
        'description',
        'type',
        'supplier',
        'price',
        'stock',
        'min_stock',
        'unit',
        'attributes',
    ];

    protected $casts = [
        'stock' => 'integer',
        'min_stock' => 'integer',
        'attributes' => 'array',
    ];

    public function getPriceAttribute(mixed $value): Money
    {
        return Money::USD((int) ($value ?? 0));
    }

    public function setPriceAttribute(mixed $value): void
    {
        if ($value instanceof Money) {
            $this->attributes['price'] = $value->getAmount();
        } else {
            $this->attributes['price'] = (int) round(((float) $value) * 100);
        }
    }

    /**
     * Convert item to an array formatted specifically for LLM tool consumption.
     * Guarantees price is a human-readable formatted string ("$45.00") and float ("45.0"),
     * avoiding raw integer cents ambiguity.
     *
     * @return array<string, mixed>
     */
    public function toLlmArray(): array
    {
        $cents = (int) $this->price->getAmount();
        $formattedPrice = '$'.number_format($cents / 100, 2);
        $floatPrice = round($cents / 100, 2);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'type' => $this->type,
            'stock' => $this->stock,
            'min_stock' => $this->min_stock,
            'unit' => $this->unit ?? $this->attributes['unit'] ?? 'unidad',
            'price' => $formattedPrice,
            'price_usd' => $floatPrice,
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        if ($activity instanceof ActivityLog && isset($this->business_id)) {
            $activity->business_id = $this->business_id;
        }
    }

    public function requiredSupplies(): BelongsToMany
    {
        return $this->belongsToMany(
            InventoryItem::class,
            'service_supplies',
            'service_id',
            'supply_id'
        )->withPivot('quantity_required')->withTimestamps();
    }

    public function scopeServices(Builder $query): Builder
    {
        return $query->where('type', 'service');
    }

    public function scopeSupplies(Builder $query): Builder
    {
        return $query->where('type', '!=', 'service');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(InventoryTransaction::class, 'inventory_item_id');
    }

    public function isLowStock(): bool
    {
        return $this->type !== 'service' && $this->stock <= $this->min_stock;
    }
}
