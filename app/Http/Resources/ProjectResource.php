<?php

namespace App\Http\Resources;

use App\Models\StudentTransferRequest;
use App\Services\PhaseCertificateService;
use App\Services\ProposalWorkflowService;
use App\Support\FypPhases;
use App\Support\FypProposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $deliverableRow = in_array($this->current_phase, FypPhases::deliverablePhases(), true)
            && $this->relationLoaded('phases')
            ? $this->phases->firstWhere('phase', $this->current_phase)
            : null;

        $usingDeliverableWorkflow = (bool) $deliverableRow;
        $workflowStage = $usingDeliverableWorkflow
            ? ($deliverableRow->workflow_stage ?? 'draft')
            : ($this->workflow_stage ?? 'draft');

        $displayStage = $usingDeliverableWorkflow
            ? FypProposal::displayDeliverableStage($workflowStage)
            : FypProposal::displayStage($workflowStage);

        $stageLabel = $usingDeliverableWorkflow
            ? ($workflowStage === 'revision_required'
                ? FypProposal::deliverableStageLabel('revision_required')
                : FypProposal::deliverableStageLabel($displayStage))
            : ($workflowStage === 'revision_required'
                ? FypProposal::stageLabel('revision_required')
                : FypProposal::stageLabel($displayStage));

        $user = $request->user();
        $isAssignedSupervisor = $user && (int) $this->supervisor_id === (int) $user->id;
        $isProjectMember = $user && app(ProposalWorkflowService::class)->isProjectMember($user, $this->resource);
        $reviewPhase = $usingDeliverableWorkflow ? $this->current_phase : 'proposal';

        // Who may see an evaluator's identity vs. just their marks/comments, and whether that
        // changes once the phase is finalized, is entirely admin-configurable — independently
        // for Proposal, Phase 1, and Phase 2 (Proposal Settings → Evaluator Visibility). Nothing
        // here is role- or phase-hardcoded beyond "which setting key applies to which
        // relationship to the project."
        $evaluatorVisibility = FypProposal::evaluatorVisibilityForPhase($reviewPhase);
        $timing = $workflowStage === 'approved' ? 'after_decision' : 'during_review';
        $supervisorSeesIdentity = $isAssignedSupervisor && $evaluatorVisibility["supervisor_view_identity_{$timing}"];
        $studentSeesIdentity = $isProjectMember && $evaluatorVisibility["student_view_identity_{$timing}"];
        $supervisorSeesMarks = $isAssignedSupervisor && $evaluatorVisibility["supervisor_view_marks_{$timing}"];
        $studentSeesMarks = $isProjectMember && $evaluatorVisibility["student_view_marks_{$timing}"];

        $canViewEvaluatorIdentities = FypProposal::canViewEvaluatorNames($user) || $supervisorSeesIdentity || $studentSeesIdentity;
        $canViewEvaluatorReviews = $canViewEvaluatorIdentities || $supervisorSeesMarks || $studentSeesMarks;
        $evaluatorCount = $this->relationLoaded('evaluators') ? $this->evaluators->count() : 0;
        $loadedReviews = $this->relationLoaded('evaluatorReviews') ? $this->evaluatorReviews : collect();
        $reviewCount = $loadedReviews->where('fyp_phase', $reviewPhase)->count();
        $pendingEvaluatorIds = $workflowStage === 'evaluator_review'
            ? FypProposal::pendingEvaluatorIds($this->resource, $reviewPhase)
            : [];

        $activeChangeRequest = $this->relationLoaded('activeSupervisorChangeRequest')
            ? $this->activeSupervisorChangeRequest
            : null;

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'area_of_specialization' => $this->area_of_specialization,
            'academic_year' => $this->academic_year,
            'current_phase' => $this->current_phase,
            'current_phase_label' => FypPhases::label($this->current_phase),
            'workflow_stage' => $workflowStage,
            'workflow_stage_label' => $stageLabel,
            'display_stage' => $displayStage,
            'using_deliverable_workflow' => $usingDeliverableWorkflow,
            'viewer_is_project_leader' => $user ? (int) $this->student_id === (int) $user->id : false,
            'viewer_can_resubmit_proposal' => $user
                && (int) $this->student_id === (int) $user->id
                && (
                    $workflowStage === 'revision_required'
                    || ($usingDeliverableWorkflow && in_array($workflowStage, ['revision_required'], true))
                ),
            'awaiting_evaluator_reviews' => $workflowStage === 'evaluator_review'
                && $evaluatorCount > 0
                && $reviewCount > 0
                && $reviewCount < $evaluatorCount,
            'evaluator_reviews_submitted' => $this->when(
                $this->relationLoaded('evaluatorReviews'),
                fn () => $reviewCount
            ),
            'evaluator_resubmit_mode' => $this->evaluator_resubmit_mode,
            'evaluator_resubmit_mode_label' => FypProposal::evaluatorResubmitModeLabel($this->evaluator_resubmit_mode),
            'supervisor_status' => $this->supervisor_status,
            'supervisor_rejection_feedback' => $this->supervisor_rejection_feedback,
            'supervisor_revision_feedback' => $this->supervisor_revision_feedback,
            'viewer_can_supervisor_review_revision' => $user
                && (int) $this->supervisor_id === (int) $user->id
                && $workflowStage === 'supervisor_revision_pending',
            'can_manage_supervisor_revision_review' => FypProposal::canManageSupervisorRevisionReview(
                $user,
                $this->supervisor_id,
                $workflowStage
            ),
            'viewer_can_supervisor_review_deliverable' => $user
                && (int) $this->supervisor_id === (int) $user->id
                && $usingDeliverableWorkflow
                && $workflowStage === 'supervisor_review',
            'proposal_submitted_at' => $this->proposal_submitted_at?->toDateTimeString(),
            'status' => $this->status,
            'student' => new UserResource($this->whenLoaded('student')),
            'supervisor' => new UserResource($this->whenLoaded('supervisor')),
            'phases' => ProjectPhaseResource::collection($this->whenLoaded('phases')),
            'members' => ProjectMemberResource::collection($this->whenLoaded('members')),
            'invitations' => ProjectInvitationResource::collection($this->whenLoaded('invitations')),
            'evaluator_count' => $this->when(
                $this->relationLoaded('evaluators'),
                fn () => $this->evaluators->count()
            ),
            'viewer_is_assigned_evaluator' => $user && $this->relationLoaded('evaluators')
                ? $this->evaluators->contains('evaluator_id', $user->id)
                : false,
            'viewer_has_submitted_review' => $user && $this->relationLoaded('evaluatorReviews')
                ? $loadedReviews->where('fyp_phase', $reviewPhase)->contains('evaluator_id', $user->id)
                : false,
            'viewer_evaluator_review' => $this->when(
                $user && $this->relationLoaded('evaluatorReviews'),
                function () use ($user, $loadedReviews, $reviewPhase) {
                    $review = $loadedReviews->where('fyp_phase', $reviewPhase)->firstWhere('evaluator_id', $user->id);

                    return $review ? new EvaluatorReviewResource($review) : null;
                }
            ),
            'viewer_can_invite_members' => $user
                && (int) $this->student_id === (int) $user->id
                && ! $usingDeliverableWorkflow
                && in_array($workflowStage, ['invitations_pending', 'supervisor_pending', 'supervisor_rejected'], true),
            'team_invite' => $this->when(
                $this->relationLoaded('members') && $this->relationLoaded('invitations'),
                fn () => app(ProposalWorkflowService::class)->teamInviteMeta($this->resource)
            ),
            'can_manage_team' => FypProposal::canManageProjectTeam($user),
            'can_manage_team_members' => FypProposal::canManageProjectTeamFor($user, $this->resource),
            'can_transfer_leadership' => $user
                && $this->status === 'active'
                && ((int) $this->student_id === (int) $user->id || FypProposal::canManageProjectTeamFor($user, $this->resource)),
            'can_request_transfer' => $user
                && $isProjectMember
                && ! $isAssignedSupervisor
                && (int) $this->student_id !== (int) $user->id,
            'viewer_transfer_request' => $this->when($user && $isProjectMember, function () use ($user) {
                $transferRequest = StudentTransferRequest::query()
                    ->where('student_id', $user->id)
                    ->where('from_project_id', $this->id)
                    ->whereIn('status', StudentTransferRequest::ACTIVE_STATUSES)
                    ->latest('id')
                    ->first();

                return $transferRequest
                    ? new StudentTransferRequestResource($transferRequest->load('toProject'))
                    : null;
            }),
            'can_decide_incoming_transfers' => $user
                && ((int) $this->student_id === (int) $user->id || FypProposal::canManageProjectTeamFor($user, $this->resource)),
            'incoming_transfer_requests' => $this->when(
                $user && ((int) $this->student_id === (int) $user->id || FypProposal::canManageProjectTeamFor($user, $this->resource)),
                fn () => StudentTransferRequestResource::collection(
                    StudentTransferRequest::query()
                        ->where('to_project_id', $this->id)
                        ->where('status', 'pending_leader')
                        ->with(['student', 'fromProject', 'toProject'])
                        ->latest('id')
                        ->get()
                )
            ),
            'can_return_to_team_formation' => FypProposal::canReturnProjectToTeamFormation($user, $workflowStage),
            'can_cancel_invitations' => FypProposal::canCancelInvitation($user, $this->resource),
            'can_accept_invitations_on_behalf' => FypProposal::canAdminManageTeamFormation($user, $this->resource),
            'can_admin_manage_team' => FypProposal::canAdminManageTeamFormation($user, $this->resource),
            'can_direct_add_members' => FypProposal::canAdminManageTeamFormation($user, $this->resource),
            'can_manage_supervisor_invitation' => FypProposal::canRespondSupervisorInvitation($user, $this->resource)
                && (int) $this->supervisor_id !== (int) $user?->id,
            'can_request_supervisor_change' => FypProposal::canRequestSupervisorChange($user, $this->resource),
            'supervisor_change_request' => $activeChangeRequest
                ? new SupervisorChangeRequestResource($activeChangeRequest)
                : null,
            'viewer_can_respond_as_current_supervisor' => FypProposal::canRespondSupervisorChangeAsCurrent(
                $user,
                $activeChangeRequest
            ),
            'viewer_can_respond_as_new_supervisor' => FypProposal::canRespondSupervisorChangeAsNew(
                $user,
                $activeChangeRequest
            ),
            'viewer_can_decide_supervisor_change' => FypProposal::canDecideActiveSupervisorChange(
                $user,
                $activeChangeRequest
            ),
            'viewer_can_manage_supervisor_change' => FypProposal::canManageSupervisorChange(
                $user,
                $activeChangeRequest
            ),
            'can_manage_evaluator_reviews' => FypProposal::canManageEvaluatorReviews($user)
                && $workflowStage === 'evaluator_review'
                && count($pendingEvaluatorIds) > 0,
            'pending_evaluators' => $this->when(
                FypProposal::canManageEvaluatorReviews($user) && $workflowStage === 'evaluator_review',
                function () use ($pendingEvaluatorIds) {
                    if (! $this->relationLoaded('evaluators')) {
                        return [];
                    }

                    return $this->evaluators
                        ->whereIn('evaluator_id', $pendingEvaluatorIds)
                        ->map(fn ($assignment) => [
                            'id' => $assignment->evaluator_id,
                            'name' => $assignment->evaluator?->name,
                        ])
                        ->values();
                }
            ),
            'can_decide_phase_repeat' => $usingDeliverableWorkflow
                && $deliverableRow
                && $deliverableRow->status !== 'approved'
                && FypProposal::canDecidePhaseRepeat($user),
            'repeat_decision' => $this->repeat_decision,
            'repeat_decision_phase' => $this->repeat_decision_phase,
            'repeat_notes' => $this->repeat_notes,
            'repeat_decided_at' => $this->repeat_decided_at?->toDateTimeString(),
            'is_trashed' => $this->trashed(),
            'deleted_at' => $this->deleted_at?->toDateTimeString(),
            'can_force_delete' => FypProposal::canManageDeletedProjects($user) && $this->trashed(),
            'can_assign_evaluators' => FypProposal::canAssignEvaluatorsOnProject(
                $user,
                $workflowStage
            ),
            'can_keep_existing_evaluators' => FypProposal::canAssignEvaluatorsOnProject($user, $workflowStage)
                && $usingDeliverableWorkflow
                && $workflowStage === 'committee_review'
                && $evaluatorCount > 0,
            'can_return_deliverable_from_committee' => FypProposal::canAssignEvaluatorsOnProject($user, $workflowStage)
                && $usingDeliverableWorkflow
                && $workflowStage === 'committee_review',
            'evaluators' => $canViewEvaluatorIdentities
                ? ProjectEvaluatorResource::collection($this->whenLoaded('evaluators'))
                : [],
            'evaluator_reviews' => $canViewEvaluatorReviews
                ? $this->evaluatorReviewsPayload($canViewEvaluatorIdentities)
                : [],
            'can_view_evaluator_marks' => $canViewEvaluatorReviews,
            'workflow_logs' => ProposalWorkflowLogResource::collection($this->whenLoaded('workflowLogs')),
            'approved_certificates' => $this->when(
                $isProjectMember,
                fn () => app(PhaseCertificateService::class)->availableCertificates($this->resource)
            ),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * Evaluator reviews (marks/comments/decision) for viewers who may see the marks but not
     * necessarily who gave them (e.g. the student team, to preserve blind grading).
     */
    protected function evaluatorReviewsPayload(bool $includeEvaluatorIdentity): array
    {
        if (! $this->relationLoaded('evaluatorReviews')) {
            return [];
        }

        return $this->evaluatorReviews
            ->map(function ($review) use ($includeEvaluatorIdentity) {
                $data = (new EvaluatorReviewResource($review))->resolve();

                if (! $includeEvaluatorIdentity) {
                    $data['evaluator'] = null;
                }

                return $data;
            })
            ->values()
            ->all();
    }
}
