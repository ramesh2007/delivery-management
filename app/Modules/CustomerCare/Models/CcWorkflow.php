<?php

namespace App\Modules\CustomerCare\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CcWorkflow extends Model
{
    use HasFactory, SoftDeletes;

    protected $table = 'cc_workflows';

    protected $fillable = [
        'name',
        'code',
        'description',
        'ticket_type',
        'trigger_type',
        'version',
        'is_active',
        'is_default',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'version' => 'integer',
        'is_active' => 'boolean',
        'is_default' => 'boolean',
    ];

    /**
     * Steps that belong to this workflow, sorted by sequence
     */
    public function steps(): HasMany
    {
        return $this->hasMany(CcWorkflowStep::class, 'workflow_id')->orderBy('sequence', 'asc');
    }

    /**
     * Tickets processed through this workflow
     */
    public function tickets(): HasMany
    {
        return $this->hasMany(CcTicket::class, 'workflow_id');
    }

    /**
     * Employee who created the workflow
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Employee who last updated the workflow
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
