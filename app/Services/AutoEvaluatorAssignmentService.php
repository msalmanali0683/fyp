<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProposalSession;
use App\Models\User;
use App\Support\FypProposal;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AutoEvaluatorAssignmentService
{
    public function __construct(
        private EvaluatorCapacityService $evaluatorCapacity,
        private ProposalWorkflowService $proposalWorkflow,
        private PhaseDeliverableWorkflowService $phaseDeliverableWorkflow,
    ) {}

    protected function validatePhase(string $phase): void
    {
        if (! in_array($phase, ['proposal', 'phase_1', 'phase_2'], true)) {
            throw ValidationException::withMessages(['phase' => ['Invalid phase selected.']]);
        }
    }

    /**
     * Active projects, in the given phase, currently sitting at a stage where evaluators
     * can be assigned but have none yet — the "pending" set for that phase.
     */
    protected function pendingQuery(Builder $projectsQuery, string $phase): Builder
    {
        return (clone $projectsQuery)
            ->where('current_phase', $phase)
            ->whereEffectiveWorkflowStage(FypProposal::evaluatorAssignmentStages())
            ->whereDoesntHave('evaluators');
    }

    /**
     * Active projects, in the given phase, that have already moved past the
     * evaluator-assignment stages (evaluators assigned, or fully approved) — "completed".
     */
    protected function completedQuery(Builder $projectsQuery, string $phase): Builder
    {
        $assignmentStages = FypProposal::evaluatorAssignmentStages();

        return (clone $projectsQuery)
            ->where('current_phase', $phase)
            ->where(function (Builder $outer) use ($assignmentStages) {
                $outer->where(function (Builder $inner) use ($assignmentStages) {
                    $inner->whereEffectiveWorkflowStage($assignmentStages)->whereHas('evaluators');
                })->orWhere(function (Builder $inner) {
                    $inner->whereEffectiveWorkflowStage(['committee_final', 'committee_head_approval', 'approved']);
                });
            });
    }

    /**
     * {total, completed, pending} counts for a phase, over an already-scoped base query
     * (e.g. one session, or the full set of projects a viewer can access).
     */
    public function phaseStats(Builder $projectsQuery, string $phase): array
    {
        $this->validatePhase($phase);

        $pending = $this->pendingQuery($projectsQuery, $phase)->count();
        $completed = $this->completedQuery($projectsQuery, $phase)->count();

        return ['total' => $pending + $completed, 'completed' => $completed, 'pending' => $pending];
    }

    public function candidateProjects(ProposalSession $session, string $phase): Collection
    {
        $this->validatePhase($phase);

        return $this->pendingQuery(
            Project::query()->where('proposal_session_id', $session->id)->where('status', 'active'),
            $phase
        )->with('student:id,name')->orderBy('id')->get();
    }

    public function pendingProjectsForPhase(?ProposalSession $session, string $phase, ?array $programIds = null): LengthAwarePaginator
    {
        $this->validatePhase($phase);

        $query = Project::query()->where('status', 'active');

        if ($session) {
            $query->where('proposal_session_id', $session->id);
        }

        if ($programIds !== null) {
            $query->whereIn('program_id', $programIds);
        }

        return $this->pendingQuery($query, $phase)
            ->with(['student:id,name,email', 'supervisor:id,name,email'])
            ->orderBy('id')
            ->paginate(20);
    }

    /**
     * Evaluators eligible for auto-assignment in this phase/program, with their remaining
     * capacity computed once and tracked in memory as assignments are handed out.
     */
    protected function capacityMap(string $phase, ?int $programId): array
    {
        $evaluators = $this->evaluatorCapacity->availableEvaluators($phase, $programId);

        $map = [];
        foreach ($evaluators as $evaluator) {
            $map[$evaluator->id] = [
                'user' => $evaluator,
                'remaining' => $this->evaluatorCapacity->remainingCapacity($evaluator, $phase),
            ];
        }

        return $map;
    }

    public function preview(User $actor, ProposalSession $session, string $phase, ?int $evaluatorsPerProject = null): array
    {
        return $this->run($actor, $session, $phase, $evaluatorsPerProject, dryRun: true);
    }

    public function autoAssign(User $actor, ProposalSession $session, string $phase, ?int $evaluatorsPerProject = null): array
    {
        return $this->run($actor, $session, $phase, $evaluatorsPerProject, dryRun: false);
    }

    protected function run(User $actor, ProposalSession $session, string $phase, ?int $evaluatorsPerProject, bool $dryRun): array
    {
        if (! FypProposal::canAssignEvaluators($actor)) {
            throw ValidationException::withMessages(['evaluator' => ['Unauthorized to assign evaluators.']]);
        }

        $this->validatePhase($phase);

        $count = $evaluatorsPerProject ?: FypProposal::minEvaluators();

        if ($count < FypProposal::minEvaluators() || $count > FypProposal::maxEvaluators()) {
            throw ValidationException::withMessages([
                'evaluators_per_project' => ['Choose between '.FypProposal::minEvaluators().' and '.FypProposal::maxEvaluators().' evaluators per project.'],
            ]);
        }

        $projects = $this->candidateProjects($session, $phase);
        $capacityMap = $this->capacityMap($phase, $session->program_id);
        $totalRemainingCapacity = collect($capacityMap)->sum('remaining');

        $assignedCount = 0;
        $skippedProjects = [];

        foreach ($projects as $project) {
            $eligible = collect($capacityMap)
                ->filter(fn ($entry) => $entry['remaining'] > 0 && (int) $entry['user']->id !== (int) $project->supervisor_id)
                ->sortByDesc('remaining');

            if ($eligible->count() < $count) {
                $skippedProjects[] = ['id' => $project->id, 'title' => $project->title, 'leader_name' => $project->student?->name];

                continue;
            }

            $chosenIds = $eligible->take($count)->keys()->map(fn ($id) => (int) $id)->all();

            if (! $dryRun) {
                $service = $phase === 'proposal' ? $this->proposalWorkflow : $this->phaseDeliverableWorkflow;

                if ($phase === 'proposal') {
                    $service->assignEvaluators($actor, $project, $chosenIds);
                } else {
                    $service->assignEvaluators($actor, $project, $phase, $chosenIds);
                }
            }

            foreach ($chosenIds as $id) {
                $capacityMap[$id]['remaining']--;
            }

            $assignedCount++;
        }

        return [
            'total_candidates' => $projects->count(),
            'evaluators_per_project' => $count,
            'available_evaluators' => count($capacityMap),
            'total_remaining_capacity' => $totalRemainingCapacity,
            'assigned_count' => $assignedCount,
            'skipped_count' => count($skippedProjects),
            'skipped_projects' => $skippedProjects,
            'dry_run' => $dryRun,
        ];
    }
}
