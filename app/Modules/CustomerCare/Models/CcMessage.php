<?php

namespace App\Modules\CustomerCare\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CcMessage extends Model
{
    use HasFactory;

    protected $table = 'cc_messages';

    protected $fillable = [
        'conversation_id',
        'customer_id',
        'direction',
        'message_type',
        'external_message_id',
        'reply_to_message_id',
        'sender_type',
        'sender_id',
        'message_body',
        'attachment_url',
        'attachment_name',
        'attachment_mime_type',
        'delivery_status',
        'error_message',
        'sent_at',
        'delivered_at',
        'read_at',
    ];

    protected $casts = [
        'sent_at' => 'datetime',
        'delivered_at' => 'datetime',
        'read_at' => 'datetime',
    ];

    /**
     * Parent conversation for this message
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CcConversation::class, 'conversation_id');
    }

    /**
     * Customer linked to this message
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CcCustomer::class, 'customer_id');
    }

    /**
     * Parent message if this is a reply
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(CcMessage::class, 'reply_to_message_id');
    }

    /**
     * Replies to this message
     */
    public function replies(): HasMany
    {
        return $this->hasMany(CcMessage::class, 'reply_to_message_id');
    }

    /**
     * Sender user if sender_type is agent
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
