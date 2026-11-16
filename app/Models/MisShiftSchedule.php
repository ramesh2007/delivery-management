<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MisShiftSchedule extends Model
{
    use HasFactory;

    protected $table = 'mis_shift_schedules';

    protected $fillable = [
        'day_of_week',
        'day_name',
        'is_working_day',
        'is_half_day',
        'shift_start',
        'shift_end',
    ];

    protected $casts = [
        'day_of_week' => 'integer',
        'is_working_day' => 'boolean',
        'is_half_day' => 'boolean',
    ];
}

