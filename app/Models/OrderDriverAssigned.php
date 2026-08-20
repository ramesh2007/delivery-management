<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderDriverAssigned extends Model
{
    use HasFactory;

    protected $table = 'orders_driver_assigned';

    protected $fillable = [
        'order_id',
        'order_number',
        'assigned_driver_user_id',
        'driver_name',
        'zone',
        'order_status',
        'driver_status',
        'assigned_at',
        'accepted_at',
        'started_at',
        'delivered_at',
        'cancelled_at',
        'refund_at',
        'exchange_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'accepted_at' => 'datetime',
        'started_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'refund_at' => 'datetime',
        'exchange_at' => 'datetime',
    ];

    /**
     * Get the order associated with this driver assignment.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Get the driver user assigned to this order.
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_driver_user_id');
    }
}
