<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MisOrderSla extends Model
{
    use HasFactory;

    protected $table = 'mis_order_slas';

    protected $fillable = [
        'order_id',
        'order_number',
        'sla_type',
        'sla_duration_minutes',
        'order_placed_at',
        'sla_start_at',
        'sla_due_at',
        'sla_status',
        'is_breached',
        'breached_by_minutes',
        'total_tat_minutes',
        'completed_at',
    ];

    protected $casts = [
        'sla_duration_minutes' => 'integer',
        'order_placed_at' => 'datetime',
        'sla_start_at' => 'datetime',
        'sla_due_at' => 'datetime',
        'completed_at' => 'datetime',
        'is_breached' => 'boolean',
        'breached_by_minutes' => 'integer',
        'total_tat_minutes' => 'integer',
    ];

    /**
     * Parent order.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Compute real-time remaining minutes before breach (or negative if already breached).
     */
    public function getRemainingMinutesAttribute(): ?int
    {
        if ($this->completed_at || !$this->sla_due_at) {
            return null;
        }

        return (int) now()->diffInMinutes($this->sla_due_at, false);
    }
}

