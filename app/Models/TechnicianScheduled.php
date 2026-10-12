<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TechnicianScheduled extends Model
{
    use HasFactory;

    protected $table = 'technician_scheduled';

    protected $fillable = [
        'order_id',
        'order_number',
        'order_item_id',
        'order_installation_id',
        'technician_id',
        'technician_name',
        'scheduled_date',
        'start_time',
        'end_time',
        'taken_time_in_mins',
        'scheduled_at',
        'zone',
        'building',
        'unit',
        'street',
        'address',
        'customer_name',
        'customer_phone',
        'status',
        'notes',
        'images',
        'completed_at',
    ];

    protected $casts = [
        'scheduled_date'     => 'date',
        'scheduled_at'       => 'datetime',
        'taken_time_in_mins' => 'integer',
        'completed_at'       => 'datetime',
        'images'             => 'array',
    ];

    /**
     * Get the order associated with this schedule.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Get the order item (installation required item) associated with this schedule.
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * Get the order installation details if linked.
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(OrderInstallation::class, 'order_installation_id');
    }

    /**
     * Get the technician user assigned to this schedule.
     */
    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }
}
