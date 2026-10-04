<?php

namespace App\Support;

use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

class EffectiveWorkflow
{
    public static function deliverableStageKeys(): array
    {
        return [
            'draft',
            'supervisor_review',
            'committee_review',
            'evaluators_assigned',
            'evaluator_review',
            'supervisor_revision_pending',
            'revision_required',
            'committee_final',
            'committee_head_approval',
            'approved',
        ];
    }

    public static function filterStageOptions(?string $phase = null): array
    {
        if ($phase && FypPhases::isDeliverablePhase($phase)) {
            $options = [];
            foreach (self::deliverableStageKeys() as $key) {
                $options[$key] = $key === 'revision_required'
                    ? FypProposal::deliverableStageLabel('revision_required')
                    : FypProposal::deliverableStageLabel(FypProposal::displayDeliverableStage($key));
            }

            return $options;
        }

        return config('fyp.project_workflow_stages', []);
    }

    public static function stageLabel(Project $project): string
    {
        $stage = $project->effectiveWorkflowStage();

        if (FypPhases::isDeliverablePhase($project->current_phase)) {
            return $stage === 'revision_required'
                ? FypProposal::deliverableStageLabel('revision_required')
                : FypProposal::deliverableStageLabel(FypProposal::displayDeliverableStage($stage));
        }

        return $stage === 'revision_required'
            ? FypProposal::stageLabel('revision_required')
            : FypProposal::stageLabel(FypProposal::displayStage($stage));
    }

    public static function applyNeedsMyActionFilter(Builder $query, User $user, string $needsAction): Builder
    {
        $query->where('status', 'active');

        return match ($needsAction) {
            'committee_review' => $query->whereEffectiveWorkflowStage('committee_review'),
            'committee_final' => $query->whereEffectiveWorkflowStage('committee_final'),
            'committee_head' => $query->whereEffectiveWorkflowStage('committee_head_approval'),
            'supervisor' => $query
                ->when($user->hasRole('supervisor'), fn ($q) => $q->where('supervisor_id', $user->id))
                ->whereSupervisorNeedsAction(),
            'evaluator' => $query
                ->whereHas('evaluators', fn ($e) => $e->where('evaluator_id', $user->id))
                ->whereDoesntHave(
                    'evaluatorReviews',
                    fn ($review) => $review
                        ->where('evaluator_id', $user->id)
                        ->whereColumn('evaluator_reviews.fyp_phase', 'projects.current_phase')
                ),
            'student_revision' => $query->where(function (Builder $outer) use ($user) {
                $outer->where('student_id', $user->id)
                    ->orWhereHas('members', fn ($m) => $m->where('user_id', $user->id)->where('status', 'active'));
            })->whereEffectiveWorkflowStage('revision_required'),
            default => $query,
        };
    }
}
