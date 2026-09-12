<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderInstallation extends Model
{
    use HasFactory;

    protected $table = 'order_installations';

    protected $fillable = [
        'order_item_id',
        'installation_type',
        'installation_level',
    ];

    /**
     * Get the order item associated with this installation.
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }
}
