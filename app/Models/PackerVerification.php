<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PackerVerification extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'order_item_id',
        'packer_id',
        'packer_name',
        'scanned_barcode',
        'is_verified',
        'verified_at',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'verified_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    public function packerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packer_id');
    }
}
