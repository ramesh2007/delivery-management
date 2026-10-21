<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
        'image',
        'quantity',
        'unit_price',
        'is_installable',
        'status',
        'is_flagged',
        'flag_reason',
        'is_packer_verified',
        'packer_verified_by',
        'packer_verified_user_name',
        'packer_verified_at',
        'is_vs_verified',
        'vs_verified_by',
        'vs_verified_user_name',
        'vs_verified_at',
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
        'product_tags',
        'gift_wrap',
        'gift_message',
    ];

    protected $casts = [
        'is_installable' => 'boolean',
        'is_flagged' => 'boolean',
        'is_packer_verified' => 'boolean',
        'packer_verified_at' => 'datetime',
        'is_vs_verified' => 'boolean',
        'vs_verified_at' => 'datetime',
        'assigned_at' => 'datetime',
        'picked_at' => 'datetime',
        'packed_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saved(function (OrderItem $item) {
            if ($item->order_id) {
                \Illuminate\Support\Facades\Cache::forget("order_details_endpoint_" . md5(trim((string)$item->order_id)));
                if ($item->relationLoaded('order') && $item->order && $item->order->order_number) {
                    \Illuminate\Support\Facades\Cache::forget("order_details_endpoint_" . md5(trim((string)$item->order->order_number)));
                    \Illuminate\Support\Facades\Cache::forget("order_details_endpoint_" . md5(ltrim((string)$item->order->order_number, '#')));
                }
                \Illuminate\Support\Facades\Cache::forget("admin_orders_new_p1_l15_s" . md5(''));
            }
        });
    }

    /**
     * Ensure is_installable always resolves to a boolean
     */
    public function getIsInstallableAttribute($value): bool
    {
        return (bool) $value;
    }

    /**
     * Helper to get whether this item has its installation scheduled.
     */
    public function getIsScheduledAssignedAttribute(): bool
    {
        return (bool) ($this->installation?->is_scheduled_assigned ?? false);
    }

    /**
     * Get discrepancies reported for this item.
     */
    public function discrepancies(): HasMany
    {
        return $this->hasMany(OrderItemDiscrepancy::class, 'order_item_id');
    }

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

    /**
     * Get the driver who verified this Virtual Stock item.
     */
    public function vsVerifiedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vs_verified_by');
    }

    /**
     * Get the installation associated with this order item.
     */
    public function installation(): HasOne
    {
        return $this->hasOne(OrderInstallation::class, 'order_item_id');
    }

    /**
     * Get all installations associated with this order item.
     */
    public function installations(): HasMany
    {
        return $this->hasMany(OrderInstallation::class, 'order_item_id');
    }
    public function returnReplacements(): HasMany
    {
        return $this->hasMany(OrderReturnReplacement::class, 'order_item_id')->orderBy('created_at', 'desc');
    }

    /**
     * Get the technician schedule associated with this order item.
     */
    public function technicianSchedule(): HasOne
    {
        return $this->hasOne(TechnicianScheduled::class, 'order_item_id');
    }

    /**
     * Get all technician schedules associated with this order item.
     */
    public function technicianSchedules(): HasMany
    {
        return $this->hasMany(TechnicianScheduled::class, 'order_item_id');
    }

    /**
     * Get all historical technician schedule logs for this order item.
     */
    public function scheduleHistories(): HasManyThrough
    {
        return $this->hasManyThrough(TechnicianScheduleHistory::class, TechnicianScheduled::class, 'order_item_id', 'technician_scheduled_id');
    }
    /**
     * Get all installation proof images for this item.
     */
    public function installationImages(): HasMany
    {
        return $this->hasMany(InstallationImage::class, 'order_item_id');
    }
}
