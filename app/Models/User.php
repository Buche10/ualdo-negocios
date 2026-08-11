<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable {
        hasRole as spatieHasRole;
        assignRole as spatieAssignRole;
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'business_id',
        'role',
        'telegram_chat_id',
        'name',
        'email',
        'email_verified_at',
        'password',
    ];

    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }

    public function isOwner(): bool
    {
        return $this->hasRole('owner');
    }

    public function isReceptionist(): bool
    {
        return $this->hasRole('receptionist');
    }

    public function isDoctor(): bool
    {
        return $this->hasRole('doctor');
    }

    /**
     * Unified hasRole check delegating to Spatie with fallback to role column.
     */
    public function hasRole(mixed $roles, ?string $guard = null): bool
    {
        try {
            if ($this->spatieHasRole($roles, $guard)) {
                return true;
            }
        } catch (\Throwable $e) {
            // Fallback if role is not in Spatie roles table yet
        }

        if (is_string($roles) && $this->role === $roles) {
            return true;
        }

        if (is_array($roles) && in_array($this->role, $roles, true)) {
            return true;
        }

        return false;
    }

    /**
     * Assign role maintaining synchronization between Spatie roles and role column.
     *
     * @param  mixed  ...$roles
     */
    public function assignRole(...$roles): mixed
    {
        $firstRole = is_array($roles[0] ?? null) ? ($roles[0][0] ?? null) : ($roles[0] ?? null);
        if (is_string($firstRole)) {
            $this->forceFill(['role' => $firstRole])->save();
        }

        try {
            return $this->spatieAssignRole(...$roles);
        } catch (\Throwable $e) {
            return $this;
        }
    }

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
