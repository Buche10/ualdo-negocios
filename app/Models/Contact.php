<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    protected $fillable = [
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

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }
}
