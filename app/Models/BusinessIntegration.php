<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class BusinessIntegration extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'provider',
        'base_url',
        'api_key',
        'enabled',
        'config',
        'last_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'api_key' => 'encrypted',
            'enabled' => 'boolean',
            'config' => 'array',
            'last_synced_at' => 'datetime',
        ];
    }
}
