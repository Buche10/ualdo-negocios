<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;

class Business extends Model
{
    use HasFactory, LogsActivity;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function tapActivity(Activity $activity, string $eventName): void
    {
        if ($activity instanceof ActivityLog && isset($this->id)) {
            $activity->business_id = $this->id;
        }
    }

    protected $fillable = [
        'name',
        'slug',
        'crm_workspace_id',
        'vertical',
        'whatsapp_phone_number_id',
        'whatsapp_phone_number',
        'whatsapp_access_token',
        'timezone',
        'business_hours_start',
        'business_hours_end',
        'working_days',
        'slot_duration_minutes',
        'currency',
        'telegram_chat_id',
        'settings',
    ];

    protected $hidden = [
        'whatsapp_access_token',
    ];

    protected $casts = [
        'working_days' => 'array',
        'settings' => 'array',
        'slot_duration_minutes' => 'integer',
        'whatsapp_access_token' => 'encrypted',
    ];

    protected $appends = [
        'has_whatsapp_token',
    ];

    public function getHasWhatsappTokenAttribute(): bool
    {
        return ! empty($this->whatsapp_access_token);
    }

    public function setWhatsappPhoneNumberAttribute(?string $value): void
    {
        if (empty($value)) {
            $this->attributes['whatsapp_phone_number'] = $value;

            return;
        }

        try {
            $this->attributes['whatsapp_phone_number'] = phone($value, 'INTERNATIONAL')->formatE164();
        } catch (\Throwable $e) {
            $this->attributes['whatsapp_phone_number'] = $value;
        }
    }

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
