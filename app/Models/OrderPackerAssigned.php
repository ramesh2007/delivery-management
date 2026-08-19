<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPackerAssigned extends Model
{
    use HasFactory;

    protected $table = 'orders_packer_assigned';

    protected $fillable = [
        'order_id',
        'packer_assigned_user_id',
        'packer_assigned_user_name',
        'assigned_at',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
    ];

    /**
     * Get the order associated with this packer assignment.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Get the user (packer) assigned to pack this order.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packer_assigned_user_id');
    }
}
