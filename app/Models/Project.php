<?php

namespace App\Models;

use App\Support\FypPhases;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Project extends Model
{
    use SoftDeletes;

    protected static function booted(): void
    {
        static::saving(function (Project $project) {
            if ($project->program_id || ! $project->student_id) {
                return;
            }

            $studentProgramId = User::query()
                ->whereKey($project->student_id)
                ->value('program_id');

            if ($studentProgramId) {
                $project->program_id = $studentProgramId;
            }
        });
    }

    protected $fillable = [
        'student_id',
        'program_id',
        'proposal_session_id',
        'supervisor_id',
        'supervisor_status',
        'supervisor_rejection_feedback',
        'supervisor_revision_feedback',
        'title',
        'description',
        'area_of_specialization',
        'academic_year',
        'current_phase',
        'workflow_stage',
        'evaluator_resubmit_mode',
        'repeat_decision',
        'repeat_decision_phase',
        'repeat_notes',
        'repeat_decided_by',
        'repeat_decided_at',
        'proposal_submitted_at',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'proposal_submitted_at' => 'datetime',
            'repeat_decided_at' => 'datetime',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }

    public function proposalSession(): BelongsTo
    {
        return $this->belongsTo(ProposalSession::class);
    }

    public function leader(): BelongsTo
    {
        return $this->student();
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function repeatDecidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'repeat_decided_by');
    }

    public function phases(): HasMany
    {
        return $this->hasMany(ProjectPhase::class)->orderByRaw(
            "CASE phase WHEN 'proposal' THEN 1 WHEN 'phase_1' THEN 2 WHEN 'phase_2' THEN 3 ELSE 4 END"
        );
    }

    public function members(): HasMany
    {
        return $this->hasMany(ProjectMember::class)->where('status', 'active');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(ProjectInvitation::class);
    }

    public function evaluators(): HasMany
    {
        return $this->hasMany(ProjectEvaluator::class);
    }

    public function evaluatorReviews(): HasMany
    {
        return $this->hasMany(EvaluatorReview::class);
    }

    public function evaluatorReviewsForPhase(string $fypPhase): HasMany
    {
        return $this->evaluatorReviews()->where('fyp_phase', $fypPhase);
    }

    public function activeDeliverablePhase(): ?ProjectPhase
    {
        if (! in_array($this->current_phase, ['phase_1', 'phase_2'], true)) {
            return null;
        }

        return $this->phases()->where('phase', $this->current_phase)->first();
    }

    public function workflowLogs(): HasMany
    {
        return $this->hasMany(ProposalWorkflowLog::class)->chronological();
    }

    public function queries(): HasMany
    {
        return $this->hasMany(ProjectQuery::class);
    }

    public function supervisorChangeRequests(): HasMany
    {
        return $this->hasMany(SupervisorChangeRequest::class);
    }

    public function activeSupervisorChangeRequest(): HasOne
    {
        return $this->hasOne(SupervisorChangeRequest::class)
            ->whereIn('overall_status', SupervisorChangeRequest::ACTIVE_STATUSES)
            ->latestOfMany();
    }

    public function phase(string $phase): ?ProjectPhase
    {
        return $this->phases->firstWhere('phase', $phase);
    }

    public function effectiveWorkflowStage(): string
    {
        if (FypPhases::isDeliverablePhase($this->current_phase)) {
            $deliverable = $this->relationLoaded('phases')
                ? $this->phases->firstWhere('phase', $this->current_phase)
                : $this->phases()->where('phase', $this->current_phase)->first();

            return $deliverable?->workflow_stage ?? 'draft';
        }

        return $this->workflow_stage ?? 'draft';
    }

    public function scopeWhereEffectiveWorkflowStage(Builder $query, string|array $stages): Builder
    {
        $stages = (array) $stages;
        $deliverablePhases = FypPhases::deliverablePhases();

        return $query->where(function (Builder $outer) use ($stages, $deliverablePhases) {
            $outer->where(function (Builder $inner) use ($stages, $deliverablePhases) {
                $inner->whereNotIn('current_phase', $deliverablePhases)
                    ->whereIn('workflow_stage', $stages);
            })->orWhere(function (Builder $inner) use ($stages, $deliverablePhases) {
                $inner->whereIn('current_phase', $deliverablePhases)
                    ->whereHas('phases', function (Builder $phase) use ($stages) {
                        $phase->whereColumn('project_phases.phase', 'projects.current_phase')
                            ->whereIn('workflow_stage', $stages);
                    });
            });
        });
    }

    public function scopeWhereSupervisorNeedsAction(Builder $query): Builder
    {
        $deliverablePhases = FypPhases::deliverablePhases();
        $proposalStages = ['supervisor_pending', 'supervisor_review', 'revision_required', 'supervisor_revision_pending'];
        $deliverableStages = ['supervisor_review', 'revision_required', 'supervisor_revision_pending'];

        return $query->where(function (Builder $outer) use ($proposalStages, $deliverablePhases, $deliverableStages) {
            $outer->where(function (Builder $inner) use ($proposalStages, $deliverablePhases) {
                $inner->whereNotIn('current_phase', $deliverablePhases)
                    ->whereIn('workflow_stage', $proposalStages);
            })->orWhere(function (Builder $inner) use ($deliverablePhases, $deliverableStages) {
                $inner->whereIn('current_phase', $deliverablePhases)
                    ->whereHas('phases', function (Builder $phase) use ($deliverableStages) {
                        $phase->whereColumn('project_phases.phase', 'projects.current_phase')
                            ->whereIn('workflow_stage', $deliverableStages);
                    });
            });
        });
    }

    public static function purgeTrashedForStudent(int $studentId): void
    {
        static::onlyTrashed()
            ->where('student_id', $studentId)
            ->get()
            ->each(function (self $project) {
                Storage::disk(config('fyp.proposal.document_disk', 'public'))
                    ->deleteDirectory('proposals/'.$project->id);

                $project->forceDelete();
            });
    }
}
