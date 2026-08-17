<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'line_item_id',
        'product_id',
        'product_code',
        'barcode',
        'product_name',
        'quantity',
        'unit_price',
        'status',
        'is_packer_verified',
        'packer_verified_by',
        'packer_verified_user_name',
        'packer_verified_at',
        'assigned_to',
        'assigned_user_name',
        'assigned_at',
        'picked_by',
        'picked_user_name',
        'picked_at',
        'packed_by',
        'packed_user_name',
        'packed_at',
        'delivered_by',
        'delivered_user_name',
        'delivered_at',
    ];

    protected $casts = [
        'is_packer_verified' => 'boolean',
        'packer_verified_at' => 'datetime',
        'assigned_at' => 'datetime',
        'picked_at' => 'datetime',
        'packed_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    /**
     * Get the order that owns this item.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Get status audit logs for this order item.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class, 'order_item_id')->orderBy('created_at', 'desc');
    }

    /**
     * Get the user/picker assigned to this order item.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Get the user who picked this item.
     */
    public function pickedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'picked_by');
    }

    /**
     * Get the user who packed this item.
     */
    public function packedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packed_by');
    }

    /**
     * Get the user/driver who delivered this item.
     */
    public function deliveredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    /**
     * Get the packer who verified this item.
     */
    public function packerVerifiedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packer_verified_by');
    }
}
