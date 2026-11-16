<?php

namespace App\Modules\CustomerCare\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CcTicketActivity extends Model
{
    use HasFactory;

    protected $table = 'cc_ticket_activities';

    protected $fillable = [
        'ticket_id',
        'workflow_step_id',
        'user_id',
        'activity_type',
        'title',
        'description',
        'old_value',
        'new_value',
        'attachments',
        'metadata',
    ];

    protected $casts = [
        'old_value' => 'array',
        'new_value' => 'array',
        'attachments' => 'array',
        'metadata' => 'array',
    ];

    /**
     * Ticket that this activity belongs to
     */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(CcTicket::class, 'ticket_id');
    }

    /**
     * Workflow step related to this activity
     */
    public function workflowStep(): BelongsTo
    {
        return $this->belongsTo(CcWorkflowStep::class, 'workflow_step_id');
    }

    /**
     * User / employee who performed this action
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
