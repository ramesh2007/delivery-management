<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderItemDiscrepancy extends Model
{
    use HasFactory;

    protected $table = 'order_item_discrepancies';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'user_id',
        'user_name',
        'issue_type',
        'comment',
        'status',
        'photo_url',
    ];

    /**
     * Get the order associated with this discrepancy.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Get the order item associated with this discrepancy.
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * Get the user who reported this discrepancy.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
