<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifySyncLog extends Model
{
    protected $table = 'shopify_sync_logs';

    protected $fillable = [
        'event_type',
        'topic',
        'shop_domain',
        'shopify_order_id',
        'order_number',
        'local_order_id',
        'status',
        'error_message',
        'error_details',
        'payload',
        'headers',
        'ip_address',
        'items_count',
        'duration_ms',
    ];

    protected $casts = [
        'payload' => 'array',
        'headers' => 'array',
        'error_details' => 'array',
        'items_count' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'local_order_id');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeSuccess($query)
    {
        return $query->where('status', 'success');
    }

    public function scopeRecent($query, int $limit = 50)
    {
        return $query->orderBy('created_at', 'desc')->limit($limit);
    }
}
