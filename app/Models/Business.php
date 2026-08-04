<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Business extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'vertical',
        'whatsapp_phone_number_id',
        'whatsapp_phone_number',
        'timezone',
        'business_hours_start',
        'business_hours_end',
        'working_days',
        'slot_duration_minutes',
        'currency',
        'telegram_chat_id',
        'settings',
    ];

    protected $casts = [
        'working_days' => 'array',
        'settings' => 'array',
        'slot_duration_minutes' => 'integer',
    ];

    public function contacts(): HasMany
    {
        return $this->hasMany(Contact::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function inventoryItems(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
