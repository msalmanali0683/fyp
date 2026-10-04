<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProposalSession extends Model
{
    public const LIFECYCLE_PROPOSAL_PHASE = 'proposal_phase';

    public const LIFECYCLE_PHASE_1 = 'phase_1';

    public const LIFECYCLE_PHASE_2 = 'phase_2';

    protected $fillable = [
        'program_id',
        'name',
        'code',
        'is_submission_open',
        'initial_draft_deadline',
        'final_lock_deadline',
        'phase_1_initial_deadline',
        'phase_1_final_lock_deadline',
        'phase_1_completed_at',
        'phase_2_initial_deadline',
        'phase_2_final_lock_deadline',
        'phase_2_completed_at',
        'is_fully_locked',
        'status',
        'lifecycle_phase',
        'created_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'is_submission_open' => 'boolean',
            'is_fully_locked' => 'boolean',
            'initial_draft_deadline' => 'datetime',
            'final_lock_deadline' => 'datetime',
            'phase_1_initial_deadline' => 'datetime',
            'phase_1_final_lock_deadline' => 'datetime',
            'phase_1_completed_at' => 'datetime',
            'phase_2_initial_deadline' => 'datetime',
            'phase_2_final_lock_deadline' => 'datetime',
            'phase_2_completed_at' => 'datetime',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function extensions(): HasMany
    {
        return $this->hasMany(ProposalSessionExtension::class);
    }

    public function projects(): HasMany
    {
        return $this->hasMany(Project::class);
    }

    public function reports(): HasMany
    {
        return $this->hasMany(ProposalSessionReport::class)->latest('generated_at');
    }
}
