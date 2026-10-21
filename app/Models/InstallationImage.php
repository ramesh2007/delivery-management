<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstallationImage extends Model
{
    use HasFactory;

    protected $table = 'installation_images';

    protected $fillable = [
        'order_id',
        'order_number',
        'order_item_id',
        'order_installation_id',
        'before_install_image',
        'after_install_image',
        'notes',
    ];

    /**
     * Get the order associated with this installation image.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Get the order item associated with this installation image.
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * Get the installation associated with this image record.
     */
    public function installation(): BelongsTo
    {
        return $this->belongsTo(OrderInstallation::class, 'order_installation_id');
    }

    public function orderInstallation(): BelongsTo
    {
        return $this->belongsTo(OrderInstallation::class, 'order_installation_id');
    }
}
