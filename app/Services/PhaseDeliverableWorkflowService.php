<?php

namespace App\Services;

use App\Models\EvaluatorReview;
use App\Models\Project;
use App\Models\ProjectEvaluator;
use App\Models\ProjectPhase;
use App\Models\User;
use App\Support\FypPhases;
use App\Support\FypProposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PhaseDeliverableWorkflowService
{
    public function __construct(
        private ProposalWorkflowService $workflowService,
        private ProgramScopeService $programScope,
        private ProposalSessionService $sessionService,
        private ProjectPurgeService $projectPurgeService,
        private EvaluatorCapacityService $evaluatorCapacity,
    ) {}

    public function deliverableRow(Project $project, string $phase): ProjectPhase
    {
        $this->assertDeliverablePhase($project, $phase);

        $row = $project->phases()->where('phase', $phase)->first();

        if (! $row) {
            throw ValidationException::withMessages(['phase' => ['Phase deliverable not found.']]);
        }

        return $row;
    }

    public function assertDeliverablePhase(Project $project, string $phase): void
    {
        if (! FypPhases::isDeliverablePhase($phase)) {
            throw ValidationException::withMessages(['phase' => ['Invalid deliverable phase.']]);
        }

        if ($project->current_phase !== $phase) {
            throw ValidationException::withMessages(['phase' => ['This deliverable phase is not active for the project.']]);
        }
    }

    public function markSubmitted(ProjectPhase $row, User $actor): ProjectPhase
    {
        $row->update([
            'status' => 'submitted',
            'workflow_stage' => 'supervisor_review',
            'submitted_at' => now(),
            'feedback' => null,
            'reviewed_at' => null,
            'reviewed_by' => null,
        ]);

        $this->workflowService->log(
            $row->project,
            'supervisor_review',
            FypPhases::label($row->phase).' deliverable submitted',
            $actor,
            $actor->hasRole('student') ? 'student' : 'system',
            null,
            $row->phase
        );

        if ($row->project->supervisor_id) {
            NotificationService::send(
                $row->project->supervisor_id,
                FypPhases::label($row->phase).' Review Required',
                "Group \"{$row->project->title}\" submitted their ".FypPhases::label($row->phase).' deliverable for your review.',
                'info',
                ['project_id' => $row->project->id, 'type' => 'phase_supervisor_review']
            );
        }

        return $row->fresh()->load(['reviewer', 'project']);
    }

    public function supervisorRespond(User $actor, Project $project, string $phase, bool $accept, ?string $feedback = null): Project
    {
        $row = $this->deliverableRow($project, $phase);
        $isAssignedSupervisor = (int) $project->supervisor_id === (int) $actor->id;
        $isOfficeActor = ! $isAssignedSupervisor && FypProposal::canManageSupervisorInvitations($actor);

        if (! $isAssignedSupervisor && ! $isOfficeActor) {
            throw ValidationException::withMessages(['supervisor' => ['Unauthorized to review this deliverable.']]);
        }

        if ($row->workflow_stage !== 'supervisor_review') {
            throw ValidationException::withMessages(['project' => ['Supervisor review is not available at this stage.']]);
        }

        if (! $accept && blank($feedback)) {
            throw ValidationException::withMessages(['feedback' => ['Please provide comments when returning the deliverable.']]);
        }

        return DB::transaction(function () use ($actor, $project, $row, $phase, $accept, $feedback, $isOfficeActor) {
            if ($accept) {
                $row->update([
                    'workflow_stage' => 'committee_review',
                    'status' => 'submitted',
                    'feedback' => $feedback,
                    'reviewed_at' => now(),
                    'reviewed_by' => $actor->id,
                ]);

                $this->workflowService->log(
                    $project,
                    'committee_review',
                    'Supervisor approved '.FypPhases::label($phase).' deliverable',
                    $actor,
                    $isOfficeActor ? ($actor->roles->first()?->name ?? 'admin') : 'supervisor',
                    $feedback,
                    $phase
                );

                $this->notifyCommittee($project, $phase);
            } else {
                $row->update([
                    'workflow_stage' => 'revision_required',
                    'status' => 'revision_required',
                    'feedback' => $feedback,
                    'reviewed_at' => now(),
                    'reviewed_by' => $actor->id,
                ]);

                $this->workflowService->log(
                    $project,
                    'supervisor_review',
                    'Supervisor returned '.FypPhases::label($phase).' deliverable for revision',
                    $actor,
                    $isOfficeActor ? ($actor->roles->first()?->name ?? 'admin') : 'supervisor',
                    $feedback,
                    $phase
                );

                $this->notifyGroup($project, 'Revision Required', "Your supervisor returned the {$phase} deliverable with comments.", 'warning');
            }

            return $this->workflowService->loadProject($project->fresh());
        });
    }

    public function supervisorRevisionReview(User $actor, Project $project, string $phase, bool $proceed, ?string $feedback = null): Project
    {
        $row = $this->deliverableRow($project, $phase);
        $isAssignedSupervisor = (int) $project->supervisor_id === (int) $actor->id;
        $isOfficeActor = ! $isAssignedSupervisor && FypProposal::canManageSupervisorInvitations($actor);

        if (! $isAssignedSupervisor && ! $isOfficeActor) {
            throw ValidationException::withMessages(['supervisor' => ['Unauthorized.']]);
        }

        if ($row->workflow_stage !== 'supervisor_revision_pending') {
            throw ValidationException::withMessages(['project' => ['Supervisor revision review is not available at this stage.']]);
        }

        if (! $proceed && blank($feedback)) {
            throw ValidationException::withMessages(['feedback' => ['Please provide comments when returning the deliverable to students.']]);
        }

        $supervisor = $project->supervisor;
        $actorRole = $isAssignedSupervisor ? 'supervisor' : ($actor->roles->first()?->name ?? 'admin');

        return DB::transaction(function () use ($actor, $supervisor, $project, $row, $phase, $proceed, $feedback, $isOfficeActor, $actorRole) {
            if ($proceed) {
                $this->advanceAfterSupervisorRevisionApproval($project, $row, $phase, $actor, $feedback, $actorRole);

                if ($isOfficeActor) {
                    NotificationService::send(
                        $supervisor->id,
                        'Revision Approved on Your Behalf',
                        'The FYP office approved the revised '.FypPhases::label($phase)." deliverable for \"{$project->title}\" on your behalf.",
                        'info',
                        ['project_id' => $project->id]
                    );
                }
            } else {
                $row->update([
                    'workflow_stage' => 'revision_required',
                    'status' => 'revision_required',
                    'feedback' => $feedback,
                ]);

                $project->update(['supervisor_revision_feedback' => $feedback]);

                if ($isOfficeActor) {
                    $this->workflowService->log(
                        $project,
                        'supervisor_review',
                        "Revised {$phase} deliverable returned by FYP office on behalf of {$supervisor->name}",
                        $actor,
                        $actorRole,
                        $feedback,
                        $phase
                    );

                    $this->notifyGroup(
                        $project,
                        'Further Revision Required',
                        "The FYP office returned your revised deliverable on {$supervisor->name}'s behalf".($feedback ? " with comments: {$feedback}" : '.'),
                        'warning'
                    );

                    NotificationService::send(
                        $supervisor->id,
                        'Revision Returned on Your Behalf',
                        'The FYP office returned the revised '.FypPhases::label($phase)." deliverable for \"{$project->title}\" on your behalf.".($feedback ? " Comment: {$feedback}" : ''),
                        'warning',
                        ['project_id' => $project->id]
                    );
                } else {
                    $this->workflowService->log(
                        $project,
                        'supervisor_review',
                        'Supervisor returned revised '.FypPhases::label($phase).' deliverable for further changes',
                        $actor,
                        'supervisor',
                        $feedback,
                        $phase
                    );

                    $this->notifyGroup($project, 'Further Revision Required', $feedback ?? 'Please revise your deliverable.', 'warning');
                }
            }

            return $this->workflowService->loadProject($project->fresh());
        });
    }

    public function keepExistingEvaluators(User $assigner, Project $project, string $phase): Project
    {
        if (! FypProposal::canAssignEvaluators($assigner)) {
            throw ValidationException::withMessages(['evaluator' => ['Unauthorized to manage evaluators.']]);
        }

        $row = $this->deliverableRow($project, $phase);

        if ($row->workflow_stage !== 'committee_review') {
            throw ValidationException::withMessages(['project' => ['Evaluators cannot be confirmed at the current stage.']]);
        }

        if (! $project->evaluators()->exists()) {
            throw ValidationException::withMessages(['evaluator' => ['No evaluators are assigned yet. Assign evaluators or add them before proceeding.']]);
        }

        $row->update(['workflow_stage' => 'evaluator_review']);

        $this->workflowService->log(
            $project,
            'evaluator_review',
            'Committee kept existing evaluators for '.FypPhases::label($phase),
            $assigner,
            $assigner->roles->first()?->name ?? 'admin',
            null,
            $phase
        );

        foreach ($project->evaluators()->pluck('evaluator_id') as $evaluatorId) {
            if ($project->evaluatorReviewsForPhase($phase)->where('evaluator_id', $evaluatorId)->exists()) {
                continue;
            }

            NotificationService::send(
                $evaluatorId,
                FypPhases::label($phase).' Review Assigned',
                "Please review the {$phase} deliverable for \"{$project->title}\".",
                'info',
                ['project_id' => $project->id, 'type' => 'phase_evaluator_review']
            );
        }

        return $this->workflowService->loadProject($project->fresh());
    }

    public function assignEvaluators(User $assigner, Project $project, string $phase, array $evaluatorIds, ?string $resubmitMode = null): Project
    {
        if (! FypProposal::canAssignEvaluators($assigner)) {
            throw ValidationException::withMessages(['evaluator' => ['Unauthorized to assign evaluators.']]);
        }

        $row = $this->deliverableRow($project, $phase);

        if (! in_array($row->workflow_stage, FypProposal::evaluatorAssignmentStages(), true)
            && $row->workflow_stage !== 'revision_required') {
            throw ValidationException::withMessages(['project' => ['Evaluators cannot be changed at the current workflow stage.']]);
        }

        if ($resubmitMode && ! FypProposal::isValidEvaluatorResubmitMode($resubmitMode)) {
            throw ValidationException::withMessages(['evaluator_resubmit_mode' => ['Invalid evaluator resubmit mode selected.']]);
        }

        $evaluatorIds = collect($evaluatorIds)->unique()->values();

        if ($evaluatorIds->count() < FypProposal::minEvaluators() || $evaluatorIds->count() > FypProposal::maxEvaluators()) {
            throw ValidationException::withMessages([
                'evaluator_ids' => ['Assign between '.FypProposal::minEvaluators().' and '.FypProposal::maxEvaluators().' evaluators.'],
            ]);
        }

        foreach ($evaluatorIds as $evaluatorId) {
            $this->assertFacultyNotSupervisorOnProject($project, (int) $evaluatorId);
        }

        foreach ($evaluatorIds as $evaluatorId) {
            $evaluator = User::find($evaluatorId);
            if ($evaluator && ! $this->evaluatorCapacity->hasCapacity($evaluator, $phase, $project->id)) {
                throw ValidationException::withMessages([
                    'evaluator_ids' => ["{$evaluator->name} has reached their evaluation limit for this phase."],
                ]);
            }
        }

        $preserveReviews = $row->workflow_stage === 'revision_required';

        return DB::transaction(function () use ($assigner, $project, $row, $phase, $evaluatorIds, $preserveReviews, $resubmitMode) {
            if ($preserveReviews) {
                EvaluatorReview::query()
                    ->where('project_id', $project->id)
                    ->where('fyp_phase', $phase)
                    ->whereNotIn('evaluator_id', $evaluatorIds->all())
                    ->delete();
            } else {
                EvaluatorReview::query()
                    ->where('project_id', $project->id)
                    ->where('fyp_phase', $phase)
                    ->delete();
            }

            $project->evaluators()->delete();

            foreach ($evaluatorIds as $evaluatorId) {
                $evaluator = User::role('evaluator')->find($evaluatorId);
                if (! $evaluator) {
                    throw ValidationException::withMessages(['evaluator_ids' => ['Invalid evaluator selected.']]);
                }

                ProjectEvaluator::create([
                    'project_id' => $project->id,
                    'evaluator_id' => $evaluatorId,
                    'assigned_by' => $assigner->id,
                ]);

                if (! $preserveReviews) {
                    NotificationService::send(
                        $evaluatorId,
                        FypPhases::label($phase).' Evaluation Assigned',
                        "You have been assigned to evaluate the {$phase} deliverable for \"{$project->title}\".",
                        'info',
                        ['project_id' => $project->id, 'type' => 'phase_evaluator_assignment']
                    );
                }
            }

            $updates = ['workflow_stage' => 'evaluator_review'];
            if ($resubmitMode) {
                $project->update(['evaluator_resubmit_mode' => $resubmitMode]);
            }

            $row->update($updates);

            $this->workflowService->log(
                $project,
                'evaluators_assigned',
                'Evaluators assigned for '.FypPhases::label($phase),
                $assigner,
                $assigner->roles->first()?->name ?? 'admin',
                $resubmitMode ? 'Resubmit review mode: '.FypProposal::evaluatorResubmitModeLabel($resubmitMode) : null,
                $phase
            );

            return $this->workflowService->loadProject($project->fresh());
        });
    }

    public function returnFromCommitteeReview(User $reviewer, Project $project, string $phase, ?string $comments = null): Project
    {
        if (! FypProposal::canAssignEvaluators($reviewer)) {
            throw ValidationException::withMessages(['reviewer' => ['Unauthorized.']]);
        }

        $row = $this->deliverableRow($project, $phase);

        if ($row->workflow_stage !== 'committee_review') {
            throw ValidationException::withMessages(['project' => ['Committee review is not available at this stage.']]);
        }

        $row->update([
            'workflow_stage' => 'revision_required',
            'status' => 'revision_required',
            'feedback' => $comments,
        ]);

        $this->workflowService->log(
            $project,
            'committee_review',
            'Committee returned '.FypPhases::label($phase).' deliverable to students',
            $reviewer,
            $reviewer->roles->first()?->name ?? 'admin',
            $comments,
            $phase
        );

        $this->notifyGroup($project, 'Revision Required', 'Committee requested revision on your deliverable.', 'warning');

        return $this->workflowService->loadProject($project->fresh());
    }

    public function submitEvaluatorReview(
        User $actor,
        Project $project,
        string $phase,
        string $decision,
        ?string $comments = null,
        ?int $evaluatorId = null,
        array $answers = []
    ): Project {
        $row = $this->deliverableRow($project, $phase);

        if ($row->workflow_stage !== 'evaluator_review') {
            throw ValidationException::withMessages(['project' => ['Evaluator review is not open.']]);
        }

        $scoredAnswers = $this->workflowService->validateEvaluatorReviewAnswers($phase, $answers);

        if (blank($comments)) {
            throw ValidationException::withMessages(['comments' => ['Please provide a final comment for this evaluation.']]);
        }

        $isAssignedEvaluator = $project->evaluators()->where('evaluator_id', $actor->id)->exists();
        $isOfficeActor = FypProposal::canManageEvaluatorReviews($actor);

        if ($evaluatorId === null && $isAssignedEvaluator) {
            $evaluatorId = $actor->id;
        }

        if ($evaluatorId === null) {
            throw ValidationException::withMessages(['evaluator_id' => ['Select an evaluator to submit this review on behalf of.']]);
        }

        if ((int) $evaluatorId === (int) $actor->id && ! $isAssignedEvaluator) {
            throw ValidationException::withMessages(['evaluator' => ['You are not assigned to this project.']]);
        }

        if ((int) $evaluatorId !== (int) $actor->id && ! $isOfficeActor) {
            throw ValidationException::withMessages(['evaluator' => ['Unauthorized to submit this evaluation.']]);
        }

        if (! $project->evaluators()->where('evaluator_id', $evaluatorId)->exists()) {
            throw ValidationException::withMessages(['evaluator_id' => ['Selected evaluator is not assigned to this project.']]);
        }

        $this->assertFacultyNotSupervisorOnProject($project, (int) $evaluatorId, 'evaluator_id');

        $this->workflowService->persistEvaluatorReview(
            $project,
            $phase,
            (int) $evaluatorId,
            $decision,
            $comments,
            $scoredAnswers
        );

        $this->workflowService->log(
            $project,
            'evaluator_review',
            'Evaluator submitted '.FypPhases::label($phase).' review: '.$decision,
            $actor,
            (int) $evaluatorId === (int) $actor->id ? 'evaluator' : ($actor->roles->first()?->name ?? 'admin'),
            $comments,
            $phase
        );

        return $this->evaluateReviewerOutcomes($project->fresh(), $row->fresh(), $phase);
    }

    public function resubmitDeliverable(User $leader, Project $project, string $phase): Project
    {
        $row = $this->deliverableRow($project, $phase);

        if ((int) $project->student_id !== (int) $leader->id) {
            throw ValidationException::withMessages(['project' => ['Only the group leader can resubmit.']]);
        }

        if ($row->workflow_stage !== 'revision_required') {
            throw ValidationException::withMessages(['project' => ['Deliverable is not in revision state.']]);
        }

        if (blank($row->attachment) && blank($row->content)) {
            throw ValidationException::withMessages(['attachment' => ['Upload a revised file before resubmitting.']]);
        }

        $this->sessionService->assertCanEditPhaseDeliverable($leader, $project, $phase);

        $row->update([
            'workflow_stage' => 'supervisor_revision_pending',
            'status' => 'submitted',
            'feedback' => null,
        ]);

        $project->update(['supervisor_revision_feedback' => null]);

        $this->workflowService->log(
            $project,
            'supervisor_review',
            'Revised '.FypPhases::label($phase).' deliverable submitted by students',
            $leader,
            'student',
            null,
            $phase
        );

        if ($project->supervisor_id) {
            NotificationService::send(
                $project->supervisor_id,
                'Revised Deliverable for Review',
                "Group \"{$project->title}\" resubmitted their ".FypPhases::label($phase).' deliverable.',
                'info',
                ['project_id' => $project->id]
            );
        }

        return $this->workflowService->loadProject($project->fresh());
    }

    public function allowCarryForward(User $actor, Project $project, string $phase, ?string $notes = null): Project
    {
        if (! FypProposal::canDecidePhaseRepeat($actor)) {
            throw ValidationException::withMessages(['repeat' => ['Unauthorized to decide phase repeat outcomes.']]);
        }

        $row = $this->deliverableRow($project, $phase);

        if ($row->status === 'approved') {
            throw ValidationException::withMessages(['phase' => ['This phase has already been approved.']]);
        }

        return DB::transaction(function () use ($actor, $project, $row, $phase, $notes) {
            $project->update([
                'repeat_decision' => 'carry_forward',
                'repeat_decision_phase' => $phase,
                'repeat_notes' => $notes,
                'repeat_decided_by' => $actor->id,
                'repeat_decided_at' => now(),
            ]);

            $this->workflowService->log(
                $project,
                $row->workflow_stage ?? 'draft',
                'Approved to carry '.FypPhases::label($phase).' forward to the next session without a new proposal',
                $actor,
                $actor->roles->first()?->name ?? 'admin',
                $notes,
                $phase
            );

            $this->notifyGroup(
                $project,
                FypPhases::label($phase).' Carried Forward',
                'You have been approved to continue '.FypPhases::label($phase).' in the next available session. Your existing team and project continue — no new proposal is required.',
                'success'
            );

            return $this->workflowService->loadProject($project->fresh());
        });
    }

    public function markResubmissionRequired(User $actor, Project $project, string $phase, ?string $notes = null): void
    {
        if (! FypProposal::canDecidePhaseRepeat($actor)) {
            throw ValidationException::withMessages(['repeat' => ['Unauthorized to decide phase repeat outcomes.']]);
        }

        $row = $this->deliverableRow($project, $phase);

        if ($row->status === 'approved') {
            throw ValidationException::withMessages(['phase' => ['This phase has already been approved.']]);
        }

        if (blank($notes)) {
            throw ValidationException::withMessages(['notes' => ['Please explain why the student must submit a new proposal.']]);
        }

        $phaseLabel = FypPhases::label($phase);
        $title = $project->title;
        $memberIds = $project->members()->where('status', 'active')->pluck('user_id')->push($project->student_id)->unique()->all();

        DB::transaction(function () use ($actor, $project, $phaseLabel, $title, $memberIds, $notes) {
            NotificationService::sendToMany(
                $memberIds,
                'Resubmission Required',
                "Your {$phaseLabel} attempt for \"{$title}\" was not approved: {$notes} You must submit a brand new proposal for a future session.",
                'warning',
                ['type' => 'phase_repeat_resubmission_required']
            );

            $this->projectPurgeService->purgeProjectPermanently(
                $project,
                $actor,
                "Resubmission required for {$phaseLabel} by {$actor->name}: {$notes}"
            );
        });
    }

    public function committeeFinalReview(User $reviewer, Project $project, string $phase, bool $approve, ?string $comments = null, ?string $resubmitMode = null): Project
    {
        if (! $reviewer->hasAnyRole(FypProposal::settings()['committee_review_roles'] ?? [])) {
            throw ValidationException::withMessages(['reviewer' => ['Unauthorized.']]);
        }

        $row = $this->deliverableRow($project, $phase);

        if ($row->workflow_stage !== 'committee_final') {
            throw ValidationException::withMessages(['project' => ['Committee final review is not available.']]);
        }

        if ($approve) {
            $row->update(['workflow_stage' => 'committee_head_approval']);

            $this->workflowService->log(
                $project,
                'committee_final',
                'Committee forwarded '.FypPhases::label($phase).' deliverable to Committee Head',
                $reviewer,
                'fyp-committee-member',
                $comments,
                $phase
            );
        } else {
            if (! FypProposal::isValidEvaluatorResubmitMode($resubmitMode)) {
                throw ValidationException::withMessages(['evaluator_resubmit_mode' => ['Please select who must review after resubmission.']]);
            }

            $row->update([
                'workflow_stage' => 'revision_required',
                'status' => 'revision_required',
            ]);
            $project->update(['evaluator_resubmit_mode' => $resubmitMode]);

            $this->workflowService->log(
                $project,
                'committee_final',
                'Committee returned '.FypPhases::label($phase).' deliverable for revision',
                $reviewer,
                'fyp-committee-member',
                $comments,
                $phase
            );

            $this->notifyGroup($project, 'Revision Required', 'Committee requested revision on your deliverable.', 'warning');
        }

        return $this->workflowService->loadProject($project->fresh());
    }

    public function committeeHeadApprove(User $reviewer, Project $project, string $phase, bool $approve, ?string $comments = null, ?string $resubmitMode = null): Project
    {
        if (! $reviewer->hasRole('fyp-committee-head')) {
            throw ValidationException::withMessages(['reviewer' => ['Only Committee Head can perform final approval.']]);
        }

        $row = $this->deliverableRow($project, $phase);

        if ($row->workflow_stage !== 'committee_head_approval') {
            throw ValidationException::withMessages(['project' => ['Final approval is not available at this stage.']]);
        }

        if ($approve) {
            $row->update([
                'workflow_stage' => 'approved',
                'status' => 'approved',
                'reviewed_at' => now(),
                'reviewed_by' => $reviewer->id,
            ]);

            $this->workflowService->log(
                $project,
                'approved',
                FypPhases::label($phase).' approved by Committee Head',
                $reviewer,
                'fyp-committee-head',
                $comments,
                $phase
            );

            $this->notifyGroup($project, FypPhases::label($phase).' Approved', 'Congratulations! Your deliverable has been approved.', 'success');
        } else {
            if (! FypProposal::isValidEvaluatorResubmitMode($resubmitMode)) {
                throw ValidationException::withMessages(['evaluator_resubmit_mode' => ['Please select who must review after resubmission.']]);
            }

            $row->update([
                'workflow_stage' => 'revision_required',
                'status' => 'revision_required',
            ]);
            $project->update(['evaluator_resubmit_mode' => $resubmitMode]);

            $this->workflowService->log(
                $project,
                'committee_head_approval',
                'Committee Head returned '.FypPhases::label($phase).' deliverable for revision',
                $reviewer,
                'fyp-committee-head',
                $comments,
                $phase
            );

            $this->notifyGroup($project, 'Revision Required', 'Committee Head requested revision on your deliverable.', 'warning');
        }

        return $this->workflowService->loadProject($project->fresh());
    }

    protected function evaluateReviewerOutcomes(Project $project, ProjectPhase $row, string $phase): Project
    {
        if ($this->pendingEvaluatorReviewCount($project, $phase) > 0) {
            return $this->workflowService->loadProject($project);
        }

        $reviews = $project->evaluatorReviewsForPhase($phase)->with('evaluator')->get();
        $summary = $this->workflowService->summarizeEvaluatorReviews($reviews);

        $row->update(['workflow_stage' => 'committee_final']);

        $this->workflowService->log(
            $project,
            'committee_final',
            'All evaluators submitted '.FypPhases::label($phase).' reviews. Awaiting committee decision. '.$summary,
            null,
            'system',
            null,
            $phase
        );

        $this->notifyCommittee(
            $project,
            $phase,
            'Committee Final Review',
            'All evaluator reviews are complete. Please forward to Committee Head or return the deliverable for student revision.'
        );

        return $this->workflowService->loadProject($project->fresh());
    }

    protected function advanceAfterSupervisorRevisionApproval(Project $project, ProjectPhase $row, string $phase, User $actor, ?string $comments, string $actorRole = 'supervisor'): void
    {
        $mode = $project->evaluator_resubmit_mode ?? 'all';
        $assignedIds = $project->evaluators()->pluck('evaluator_id');

        if ($mode === 'all') {
            EvaluatorReview::query()->where('project_id', $project->id)->where('fyp_phase', $phase)->delete();
            $evaluatorsToNotify = $assignedIds;
        } else {
            $negativeEvaluatorIds = $project->evaluatorReviewsForPhase($phase)
                ->whereIn('decision', ['rejected', 'revision_required'])
                ->pluck('evaluator_id');

            EvaluatorReview::query()
                ->where('project_id', $project->id)
                ->where('fyp_phase', $phase)
                ->whereIn('evaluator_id', $negativeEvaluatorIds)
                ->delete();

            $reviewedIds = $project->evaluatorReviewsForPhase($phase)->pluck('evaluator_id');
            $evaluatorsToNotify = $negativeEvaluatorIds
                ->merge($assignedIds->diff($reviewedIds))
                ->unique()
                ->values();
        }

        $project = $project->fresh();
        $row = $row->fresh();
        $pendingReviews = $this->pendingEvaluatorReviewCount($project, $phase);

        if ($pendingReviews === 0) {
            $row->update(['workflow_stage' => 'committee_final', 'status' => 'submitted']);
            $this->workflowService->log(
                $project,
                'committee_final',
                'Revised deliverable approved by supervisor. No further evaluator review required.',
                $actor,
                $actorRole,
                $comments,
                $phase
            );
            $this->notifyCommittee($project, $phase);
        } else {
            $row->update(['workflow_stage' => 'evaluator_review', 'status' => 'submitted']);
            $this->workflowService->log(
                $project,
                'evaluator_review',
                'Revised deliverable approved by supervisor and sent for evaluator review',
                $actor,
                $actorRole,
                $comments,
                $phase
            );

            foreach ($evaluatorsToNotify as $evaluatorId) {
                NotificationService::send(
                    $evaluatorId,
                    FypPhases::label($phase).' Resubmitted',
                    "The {$phase} deliverable for \"{$project->title}\" requires your review again.",
                    'info',
                    ['project_id' => $project->id]
                );
            }
        }
    }

    protected function pendingEvaluatorReviewCount(Project $project, string $fypPhase): int
    {
        $assignedIds = $project->evaluators()->pluck('evaluator_id');
        $reviewedIds = $project->evaluatorReviewsForPhase($fypPhase)->pluck('evaluator_id');

        return $assignedIds->diff($reviewedIds)->count();
    }

    protected function assertFacultyNotSupervisorOnProject(Project $project, int $userId, string $field = 'evaluator_ids'): void
    {
        if ($project->supervisor_id && (int) $project->supervisor_id === $userId) {
            throw ValidationException::withMessages([
                $field => [FypProposal::supervisorEvaluatorConflictMessage()],
            ]);
        }
    }

    protected function notifyGroup(Project $project, string $title, string $message, string $type = 'info'): void
    {
        $memberIds = $project->members()->where('status', 'active')->pluck('user_id')->push($project->student_id)->unique()->all();
        NotificationService::sendToMany($memberIds, $title, $message, $type, ['project_id' => $project->id]);
    }

    protected function notifyCommittee(Project $project, string $phase, ?string $title = null, ?string $message = null): void
    {
        $title ??= FypPhases::label($phase).' Ready for Review';
        $message ??= "The {$phase} deliverable for \"{$project->title}\" is ready for FYP committee review.";

        if (! $project->program_id) {
            return;
        }

        User::query()
            ->whereIn('id', $this->programScope->committeeMemberIds((int) $project->program_id))
            ->each(function ($user) use ($project, $title, $message) {
                NotificationService::send($user, $title, $message, 'info', ['project_id' => $project->id]);
            });
    }

    /**
     * Reopens an already-approved deliverable phase for re-evaluation — even after the
     * phase/session has moved on and evaluation reports were generated. This only touches
     * this one phase's review record (status, marks, evaluators); it does not move the
     * project's current_phase or overall status backward. Once the corrected evaluation is
     * approved again, an admin re-runs the normal "Complete Phase" action to pick it up.
     */
    public function reevaluate(
        User $actor,
        Project $project,
        string $phase,
        bool $keepSameEvaluators,
        array $newEvaluatorIds,
        string $extendedDeadline,
        ?string $notes = null
    ): Project {
        if (! FypProposal::canReevaluatePhase($actor)) {
            throw ValidationException::withMessages(['reevaluate' => ['Unauthorized to reevaluate this deliverable.']]);
        }

        if (! FypPhases::isDeliverablePhase($phase)) {
            throw ValidationException::withMessages(['phase' => ['Invalid deliverable phase.']]);
        }

        $row = $project->phases()->where('phase', $phase)->first();

        if (! $row) {
            throw ValidationException::withMessages(['phase' => ['Phase deliverable not found.']]);
        }

        if ($row->status !== 'approved') {
            throw ValidationException::withMessages(['phase' => ['Only an approved deliverable can be reevaluated.']]);
        }

        $newEvaluatorIds = collect($newEvaluatorIds)->unique()->values();

        if (! $keepSameEvaluators) {
            if ($newEvaluatorIds->count() < FypProposal::minEvaluators() || $newEvaluatorIds->count() > FypProposal::maxEvaluators()) {
                throw ValidationException::withMessages([
                    'evaluator_ids' => ['Assign between '.FypProposal::minEvaluators().' and '.FypProposal::maxEvaluators().' evaluators.'],
                ]);
            }

            foreach ($newEvaluatorIds as $evaluatorId) {
                $this->assertFacultyNotSupervisorOnProject($project, (int) $evaluatorId);

                $evaluator = User::role('evaluator')->find($evaluatorId);
                if (! $evaluator) {
                    throw ValidationException::withMessages(['evaluator_ids' => ['Invalid evaluator selected.']]);
                }

                if (! $this->evaluatorCapacity->hasCapacity($evaluator, $phase, $project->id)) {
                    throw ValidationException::withMessages([
                        'evaluator_ids' => ["{$evaluator->name} has reached their evaluation limit for this phase."],
                    ]);
                }
            }
        } elseif (! $project->evaluators()->exists()) {
            throw ValidationException::withMessages(['evaluator_ids' => ['This project has no evaluators assigned to keep — reassign evaluators instead.']]);
        }

        return DB::transaction(function () use ($actor, $project, $row, $phase, $keepSameEvaluators, $newEvaluatorIds, $extendedDeadline, $notes) {
            EvaluatorReview::query()
                ->where('project_id', $project->id)
                ->where('fyp_phase', $phase)
                ->delete();

            if (! $keepSameEvaluators) {
                $project->evaluators()->delete();

                foreach ($newEvaluatorIds as $evaluatorId) {
                    ProjectEvaluator::create([
                        'project_id' => $project->id,
                        'evaluator_id' => $evaluatorId,
                        'assigned_by' => $actor->id,
                    ]);
                }
            }

            $row->update([
                'status' => 'under_review',
                'workflow_stage' => 'evaluator_review',
                'feedback' => null,
                'reviewed_at' => null,
                'reviewed_by' => null,
                'is_reevaluation' => true,
                'reevaluation_deadline' => $extendedDeadline,
                'reevaluated_by' => $actor->id,
                'reevaluated_at' => now(),
            ]);

            $phaseLabel = FypPhases::label($phase);
            $actorRole = $actor->roles->first()?->name ?? 'admin';

            $this->workflowService->log(
                $project,
                'evaluator_review',
                $phaseLabel.' reevaluation started ('.($keepSameEvaluators ? 'same evaluators' : 'new evaluators').'), extended to '.$extendedDeadline,
                $actor,
                $actorRole,
                $notes,
                $phase
            );

            $project->evaluators()->pluck('evaluator_id')->each(function ($evaluatorId) use ($project, $phaseLabel, $extendedDeadline) {
                NotificationService::send(
                    $evaluatorId,
                    $phaseLabel.' Re-evaluation Requested',
                    "Please re-review the {$phaseLabel} deliverable for \"{$project->title}\" by {$extendedDeadline}.",
                    'warning',
                    ['project_id' => $project->id, 'type' => 'phase_reevaluation']
                );
            });

            $this->notifyGroup(
                $project,
                $phaseLabel.' Being Re-evaluated',
                "Your {$phaseLabel} deliverable is being re-evaluated. New deadline: {$extendedDeadline}.",
                'warning'
            );

            return $this->workflowService->loadProject($project->fresh());
        });
    }
}
