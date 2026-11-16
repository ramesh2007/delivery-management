<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MisSlaConfig extends Model
{
    use HasFactory;

    protected $table = 'mis_sla_configs';

    protected $fillable = [
        'sla_type',
        'display_name',
        'duration_minutes',
        'is_active',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'is_active' => 'boolean',
    ];
}

