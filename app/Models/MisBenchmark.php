<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MisBenchmark extends Model
{
    use HasFactory;

    protected $table = 'mis_benchmark';

    protected $fillable = [
        'role',
        'stage',
        'display_name',
        'benchmark_minutes',
        'is_freeze_active',
        'freeze_start',
        'freeze_end',
        'freeze_windows',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'benchmark_minutes' => 'integer',
        'is_freeze_active' => 'boolean',
        'freeze_windows' => 'array',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];
}

