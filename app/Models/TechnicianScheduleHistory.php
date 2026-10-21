<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianScheduleHistory extends Model
{
    use HasFactory;

    protected $table = 'technician_scheduled_history';

    protected $fillable = [
        'technician_scheduled_id',
        'technician_id',
        'technician_name',
        'scheduled_date',
        'start_time',
        'end_time',
        'taken_time_in_mins',
        'status',
        'notes',
        'reschedule_reason',
        'rescheduled_by',
    ];

    protected $casts = [
        'scheduled_date'     => 'date',
        'taken_time_in_mins' => 'integer',
    ];

    /**
     * Get the master technician schedule record associated with this history log.
     */
    public function technicianSchedule(): BelongsTo
    {
        return $this->belongsTo(TechnicianScheduled::class, 'technician_scheduled_id');
    }

    /**
     * Get the technician assigned in this historical schedule.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    /**
     * Get the user who rescheduled this record.
     */
    public function rescheduledByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rescheduled_by');
    }
}
