<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class OrderInstallation extends Model
{
    use HasFactory;

    protected $table = 'order_installations';

    protected $fillable = [
        'order_item_id',
        'installation_type',
        'installation_level',
        'status',
        'is_scheduled_assigned',
        'notes',
        'images',
        'completed_at',
    ];

    protected $casts = [
        'is_scheduled_assigned' => 'boolean',
        'images' => 'array',
        'completed_at' => 'datetime',
    ];

    /**
     * Get the order item associated with this installation.
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * Get the technician schedule associated with this installation.
     */
    public function technicianSchedule(): HasOne
    {
        return $this->hasOne(TechnicianScheduled::class, 'order_installation_id');
    }

    /**
     * Get the installation proof image records associated with this installation.
     */
    public function installationImages(): HasMany
    {
        return $this->hasMany(InstallationImage::class, 'order_installation_id');
    }

    public function proofImages(): HasMany
    {
        return $this->hasMany(InstallationImage::class, 'order_installation_id');
    }
}