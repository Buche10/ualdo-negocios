<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class InventoryItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'type',
        'price',
        'stock',
        'attributes',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'attributes' => 'array',
    ];
}

