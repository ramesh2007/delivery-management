<?php

namespace App\Modules\CustomerCare\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CcConversation extends Model
{
    use HasFactory;

    protected $table = 'cc_conversations';

    protected $fillable = [
        'customer_id',
        'channel',
        'channel_account_id',
        'external_conversation_id',
        'assigned_to',
        'status',
        'priority',
        'subject',
        'last_message_at',
        'last_message_preview',
        'unread_count',
        'closed_at',
    ];

    protected $casts = [
        'unread_count' => 'integer',
        'last_message_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * Customer associated with this conversation
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CcCustomer::class, 'customer_id');
    }

    /**
     * Agent/user assigned to handle this conversation
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * All messages in this conversation
     */
    public function messages(): HasMany
    {
        return $this->hasMany(CcMessage::class, 'conversation_id');
    }

    /**
     * Tickets created from or linked to this conversation
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(CcTicket::class, 'conversation_id');
    }
}
