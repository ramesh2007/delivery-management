<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderReturnReplacement extends Model
{
    use HasFactory;

    protected $table = 'order_return_replacements';

    protected $fillable = [
        'order_id',
        'order_item_id',
        'user_id',
        'user_name',
        'action_type',
        'reason',
        'notes',
        'photo_url',
        'status',
    ];

    /**
     * Get the order associated with this return/replacement.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Get the order item associated with this return/replacement.
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * Get the user who requested this return/replacement.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
