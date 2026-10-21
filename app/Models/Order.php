<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'customer_name',
        'customer_phone',
        'delivery_address',
        'notes',
        'total_amount',
        'payment_method',
        'payment_status',
        'collected_amount',
        'status',
        'bag_count',
        'is_bag_verified',
        'assigned_to',
        'assigned_user_name',
        'assigned_at',
        'packed_by',
        'packed_user_name',
        'packed_at',
        'delivered_by',
        'delivered_user_name',
        'delivered_at',
    ];

    protected $casts = [
        'bag_count' => 'integer',
        'is_bag_verified' => 'boolean',
        'assigned_at' => 'datetime',
        'packed_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    protected $appends = [
        'product_tag',
    ];

    /**
     * Get the order-level product_tag attribute ('VS' if any item has VS, else null).
     */
    public function getProductTagAttribute(): ?string
    {
        if (!$this->relationLoaded('items') || !$this->items) {
            return null;
        }

        foreach ($this->items as $item) {
            if ($item->product_tag === 'VS') {
                return 'VS';
            }
        }

        return null;
    }

    /**
     * Get all items associated with this order.
     */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'order_id');
    }

    /**
     * Get status audit logs for this order.
     */
    public function logs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class, 'order_id')->orderBy('created_at', 'desc');
    }

    /**
     * Get packer verifications for this order.
     */
    public function packerVerifications(): HasMany
    {
        return $this->hasMany(PackerVerification::class, 'order_id')->orderBy('created_at', 'desc');
    }

    /**
     * Get discrepancies reported for items in this order.
     */
    public function discrepancies(): HasMany
    {
        return $this->hasMany(OrderItemDiscrepancy::class, 'order_id')->orderBy('created_at', 'desc');
    }

    /**
     * Get return and replacement records for this order.
     */
    public function returnReplacements(): HasMany
    {
        return $this->hasMany(OrderReturnReplacement::class, 'order_id')->orderBy('created_at', 'desc');
    }

    /**
     * Get the user/picker assigned to this order.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
    public function pickedUser()
    {
        return $this->belongsTo(User::class, 'picked_by');
    }
    /**
     * Get the user who packed this order.
     */
    public function packedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'packed_by');
    }

    /**
     * Get the user/driver who delivered this order.
     */
    public function deliveredUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    /**
     * Get the current packer assignment for this order.
     */
    public function packerAssignment(): HasOne
    {
        return $this->hasOne(OrderPackerAssigned::class, 'order_id');
    }

    /**
     * Get the current driver assignment for this order.
     */
    public function driverAssignment(): HasOne
    {
        return $this->hasOne(OrderDriverAssigned::class, 'order_id');
    }

    /**
     * Get the payment tracking details for this order.
     */
    public function payment(): HasOne
    {
        return $this->hasOne(OrderPayment::class, 'order_id');
    }
    // public function returnReplacements(): HasMany
    // {
    //     return $this->hasMany(OrderReturnReplacement::class, 'order_id')->orderBy('created_at', 'desc');
    // }

    /**
     * Get technician schedules for this order.
     */
    public function technicianSchedules(): HasMany
    {
        return $this->hasMany(TechnicianScheduled::class, 'order_id');
    }

    /**
     * Get all installation records for items belonging to this order.
     */
    public function orderInstallations(): HasManyThrough
    {
        return $this->hasManyThrough(OrderInstallation::class, OrderItem::class, 'order_id', 'order_item_id');
    }

    public function installations(): HasManyThrough
    {
        return $this->hasManyThrough(OrderInstallation::class, OrderItem::class, 'order_id', 'order_item_id');
    }

    /**
     * Get all installation proof images for this order.
     */
    public function installationImages(): HasMany
    {
        return $this->hasMany(InstallationImage::class, 'order_id');
    }
}



