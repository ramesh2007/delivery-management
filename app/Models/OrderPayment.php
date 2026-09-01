<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderPayment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'shopify_order_id',
        'payment_method',
        'payment_status',
        'paid_amount',
        'total_price',
        'total_outstanding',
        'currency',
        'processed_at',
        'shopify_created_at',
        'shopify_updated_at',
        'raw_payment_details',
    ];

    protected $casts = [
        'paid_amount' => 'float',
        'total_price' => 'float',
        'total_outstanding' => 'float',
        'processed_at' => 'datetime',
        'shopify_created_at' => 'datetime',
        'shopify_updated_at' => 'datetime',
        'raw_payment_details' => 'array',
    ];

    /**
     * Get the order associated with this payment record.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
