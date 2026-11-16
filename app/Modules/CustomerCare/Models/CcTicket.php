<?php

namespace App\Modules\CustomerCare\Models;

use App\Models\Order;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CcTicket extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cc_tickets';

    protected $fillable = [
        'ticket_number',
        'customer_id',
        'conversation_id',
        'order_id',
        'order_number',
        'ticket_type',
        'category',
        'subject',
        'description',
        'priority',
        'status',
        'workflow_id',
        'current_step_id',
        'assigned_to',
        'created_by',
        'resolution',
        'resolved_at',
        'closed_at',
        'due_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'due_at' => 'datetime',
    ];

    /**
     * Customer associated with this ticket
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(CcCustomer::class, 'customer_id');
    }

    /**
     * Conversation from which this ticket originated
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CcConversation::class, 'conversation_id');
    }

    /**
     * Associated order in OMS
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    /**
     * Associated workflow pipeline
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(CcWorkflow::class, 'workflow_id');
    }

    /**
     * Current step in the workflow
     */
    public function currentStep(): BelongsTo
    {
        return $this->belongsTo(CcWorkflowStep::class, 'current_step_id');
    }

    /**
     * Assigned employee
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Employee who created the ticket
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Activities / audit log for this ticket
     */
    public function activities(): HasMany
    {
        return $this->hasMany(CcTicketActivity::class, 'ticket_id');
    }
}
