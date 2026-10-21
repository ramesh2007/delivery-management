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
        'shopify_status',
        'cancelled_at',
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
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'bag_count' => 'integer',
        'is_bag_verified' => 'boolean',
        'assigned_at' => 'datetime',
        'packed_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
    ];

    /**
     * Scope for cancelled orders
     */
    public function scopeCancelled($query)
    {
        return $query->where(function ($q) {
            $q->where('status', 'cancelled')
              ->orWhere('shopify_status', 'cancelled')
              ->orWhereNotNull('cancelled_at');
        });
    }

    /**
     * Scope for active new/pending orders
     */
    public function scopeNewOrders($query)
    {
        return $query->where('status', 'pending')
                     ->where(function ($q) {
                         $q->whereNull('shopify_status')
                           ->orWhere('shopify_status', '!=', 'cancelled');
                     })
                     ->whereNull('cancelled_at');
    }

    /**
     * Check if order is cancelled
     */
    public function isCancelled(): bool
    {
        return $this->status === 'cancelled' || $this->shopify_status === 'cancelled' || !empty($this->cancelled_at);
    }

    protected static function booted(): void
    {
        static::saved(function (Order $order) {
            \Illuminate\Support\Facades\Cache::forget("order_details_endpoint_" . md5(trim((string)$order->id)));
            if ($order->order_number) {
                \Illuminate\Support\Facades\Cache::forget("order_details_endpoint_" . md5(trim((string)$order->order_number)));
                \Illuminate\Support\Facades\Cache::forget("order_details_endpoint_" . md5(ltrim((string)$order->order_number, '#')));
            }
            \Illuminate\Support\Facades\Cache::forget("admin_orders_new_p1_l15_s" . md5(''));

            // Auto-sync fulfillment to Shopify when status changes to delivered or completed
            if ($order->wasChanged('status') && in_array($order->status, ['delivered', 'completed'])) {
                $orderId = $order->id;
                $status = $order->status;
                $driverName = $order->delivered_user_name;
                \Illuminate\Support\Facades\DB::afterCommit(function () use ($orderId, $status, $driverName) {
                    try {
                        $freshOrder = \App\Models\Order::with(['items', 'payment'])->find($orderId);
                        if ($freshOrder) {
                            app(\App\Services\ShopifyService::class)->syncOrderFulfillmentToShopify($freshOrder, [
                                'status' => $status,
                                'delivered_by' => $driverName,
                                'action' => 'order_status_saved',
                            ]);
                        }
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning("Shopify fulfillment sync on order saved event failed for order {$orderId}: " . $e->getMessage());
                    }
                });
            }
        });
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
     * Get the user/picker assigned to this order.
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }
    public function pickedUser(){
        return $this->belongsTo(User::Class,'picked_by');
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
    public function returnReplacements(): HasMany
    {
        return $this->hasMany(OrderReturnReplacement::class, 'order_id')->orderBy('created_at', 'desc');
    }

    /**
     * Get technician schedules for this order.
     */
    public function technicianSchedules(): HasMany
    {
        return $this->hasMany(TechnicianScheduled::class, 'order_id');
    }

    /**
     * Get historical technician schedule logs for this order.
     */
    public function scheduleHistories(): HasManyThrough
    {
        return $this->hasManyThrough(TechnicianScheduleHistory::class, TechnicianScheduled::class, 'order_id', 'technician_scheduled_id');
    }
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



