<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FlagImage extends Model
{
    use HasFactory;

    protected $table = 'flag_images';

    protected $fillable = [
        'order_id',
        'order_number',
        'order_item_id',
        'line_item_id',
        'discrepancy_id',
        'image_url',
        'photo_url',
        'issue_type',
        'comment',
        'user_id',
        'user_name',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'order_item_id' => 'integer',
        'discrepancy_id' => 'integer',
        'user_id' => 'integer',
    ];

    /**
     * Get image URL, falling back to photo_url if empty.
     */
    public function getImageUrlAttribute($value): ?string
    {
        return $value ?: ($this->attributes['photo_url'] ?? null);
    }

    /**
     * Get photo URL, falling back to image_url if empty.
     */
    public function getPhotoUrlAttribute($value): ?string
    {
        return $value ?: ($this->attributes['image_url'] ?? null);
    }

    /**
     * Keep image_url and photo_url in sync when image_url is set.
     */
    public function setImageUrlAttribute($value): void
    {
        $this->attributes['image_url'] = $value;
        if (empty($this->attributes['photo_url'])) {
            $this->attributes['photo_url'] = $value;
        }
    }

    /**
     * Keep photo_url and image_url in sync when photo_url is set.
     */
    public function setPhotoUrlAttribute($value): void
    {
        $this->attributes['photo_url'] = $value;
        if (empty($this->attributes['image_url'])) {
            $this->attributes['image_url'] = $value;
        }
    }

    /**
     * Get the order associated with this flag image.
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Get the order item associated with this flag image.
     */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class, 'order_item_id');
    }

    /**
     * Get the order item discrepancy record associated with this flag image.
     */
    public function discrepancy(): BelongsTo
    {
        return $this->belongsTo(OrderItemDiscrepancy::class, 'discrepancy_id');
    }

    /**
     * Get the user who reported or uploaded this flag image.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
