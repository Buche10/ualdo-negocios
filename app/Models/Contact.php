<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use BelongsToBusiness, HasFactory;

    protected $fillable = [
        'business_id',
        'phone_number',
        'name',
        'email',
        'bot_paused_until',
        'metadata',
    ];

    protected $casts = [
        'bot_paused_until' => 'datetime',
        'metadata' => 'array',
    ];

    public function setPhoneNumberAttribute(?string $value): void
    {
        if (empty($value)) {
            $this->attributes['phone_number'] = $value;

            return;
        }

        try {
            $this->attributes['phone_number'] = phone($value, 'INTERNATIONAL')->formatE164();
        } catch (\Throwable $e) {
            $this->attributes['phone_number'] = $value;
        }
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function medicalRecords(): HasMany
    {
        return $this->hasMany(MedicalRecord::class);
    }
}
