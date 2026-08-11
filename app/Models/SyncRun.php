<?php

namespace App\Models;

use App\Traits\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;

class SyncRun extends Model
{
    use BelongsToBusiness;

    protected $fillable = [
        'business_id',
        'provider',
        'direction',
        'status',
        'pulled',
        'created_count',
        'updated_count',
        'skipped',
        'errors',
        'duration_ms',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'pulled' => 'integer',
            'created_count' => 'integer',
            'updated_count' => 'integer',
            'skipped' => 'integer',
            'errors' => 'array',
            'duration_ms' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
