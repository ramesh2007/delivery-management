<?php

namespace App\Modules\CustomerCare\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CcCustomer extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cc_customers';

    protected $fillable = [
        'name',
        'phone',
        'email',
        'whatsapp_id',
        'instagram_id',
        'facebook_id',
        'external_customer_id',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Customer conversations across channels (WhatsApp, IG, FB)
     */
    public function conversations(): HasMany
    {
        return $this->hasMany(CcConversation::class, 'customer_id');
    }

    /**
     * Customer care tickets
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(CcTicket::class, 'customer_id');
    }

    /**
     * All messages linked to this customer
     */
    public function messages(): HasMany
    {
        return $this->hasMany(CcMessage::class, 'customer_id');
    }
}
