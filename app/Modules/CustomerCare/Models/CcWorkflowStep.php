<?php

namespace App\Modules\CustomerCare\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CcWorkflowStep extends Model
{
    use HasFactory;

    protected $table = 'cc_workflow_steps';

    protected $fillable = [
        'workflow_id',
        'step_key',
        'step_name',
        'step_type',
        'description',
        'sequence',
        'assigned_role',
        'assigned_to',
        'form_schema',
        'conditions',
        'actions',
        'next_step_id',
        'is_required',
        'allow_skip',
        'sla_minutes',
        'settings',
    ];

    protected $casts = [
        'sequence' => 'integer',
        'form_schema' => 'array',
        'conditions' => 'array',
        'actions' => 'array',
        'is_required' => 'boolean',
        'allow_skip' => 'boolean',
        'sla_minutes' => 'integer',
        'settings' => 'array',
    ];

    /**
     * Workflow that this step belongs to
     */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(CcWorkflow::class, 'workflow_id');
    }

    /**
     * Specific employee assigned to this step
     */
    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Default next step in sequence
     */
    public function nextStep(): BelongsTo
    {
        return $this->belongsTo(CcWorkflowStep::class, 'next_step_id');
    }

    /**
     * Steps that point to this step as their next step
     */
    public function previousSteps(): HasMany
    {
        return $this->hasMany(CcWorkflowStep::class, 'next_step_id');
    }

    /**
     * Ticket activities associated with this step
     */
    public function activities(): HasMany
    {
        return $this->hasMany(CcTicketActivity::class, 'workflow_step_id');
    }
}
