<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectInvitationResource;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\ProposalWorkflowLogResource;
use App\Http\Resources\UserResource;
use App\Models\Project;
use App\Models\ProjectInvitation;
use App\Models\User;
use App\Services\FypSettingsService;
use App\Services\ProjectService;
use App\Services\ProposalWorkflowService;
use App\Services\SupervisorCapacityService;
use App\Support\FypProposal;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProposalWorkflowController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ProposalWorkflowService $workflowService,
        private ProjectService $projectService,
        private FypSettingsService $settingsService,
        private SupervisorCapacityService $supervisorCapacity,
    ) {}

    public function settings(Request $request): JsonResponse
    {
        $teamLimits = $this->settingsService->proposalTeamLimits();
        $supervisorLimits = $this->settingsService->defaultSupervisorLimits();
        $evaluatorLimits = $this->settingsService->defaultEvaluatorLimits();
        $evaluatorVisibility = $this->settingsService->evaluatorVisibility();

        return $this->success([
            'min_members' => FypProposal::minMembers(),
            'max_members' => FypProposal::maxMembers(),
            'min_evaluators' => FypProposal::minEvaluators(),
            'max_evaluators' => FypProposal::maxEvaluators(),
            'workflow_stages' => FypProposal::workflowStages(),
            'phase_workflow_stages' => FypProposal::phaseDeliverableWorkflowStages(),
            'team_limits' => $teamLimits,
            'default_supervisor_limits' => $supervisorLimits,
            'default_evaluator_limits' => $evaluatorLimits,
            'evaluator_visibility' => $evaluatorVisibility,
            'can_manage_settings' => FypProposal::canManageProposalSettings($request->user()),
        ]);
    }

    public function updateSettings(Request $request): JsonResponse
    {
        if (! FypProposal::canManageProposalSettings($request->user())) {
            return $this->error('Unauthorized to manage proposal settings.', 403);
        }

        $rules = [
            'min_members' => ['required', 'integer', 'min:2', 'max:20'],
            'max_members' => ['required', 'integer', 'min:2', 'max:20', 'gte:min_members'],
            'min_evaluators' => ['required', 'integer', 'min:1', 'max:10'],
            'max_evaluators' => ['required', 'integer', 'min:1', 'max:10', 'gte:min_evaluators'],
            'supervisor_limits.proposal' => ['required', 'integer', 'min:0', 'max:100'],
            'supervisor_limits.phase_1' => ['required', 'integer', 'min:0', 'max:100'],
            'supervisor_limits.phase_2' => ['required', 'integer', 'min:0', 'max:100'],
            'evaluator_limits.proposal' => ['required', 'integer', 'min:0', 'max:100'],
            'evaluator_limits.phase_1' => ['required', 'integer', 'min:0', 'max:100'],
            'evaluator_limits.phase_2' => ['required', 'integer', 'min:0', 'max:100'],
            'evaluator_visibility' => ['nullable', 'array'],
        ];

        foreach (FypSettingsService::EVALUATOR_VISIBILITY_PHASES as $phase) {
            $rules["evaluator_visibility.{$phase}"] = ['nullable', 'array'];

            foreach (array_keys($this->settingsService->defaultEvaluatorVisibilityForPhase()) as $key) {
                $rules["evaluator_visibility.{$phase}.{$key}"] = ['nullable', 'boolean'];
            }
        }

        $validated = $request->validate($rules);

        $teamLimits = $this->settingsService->updateProposalTeamLimits($validated);
        $supervisorLimits = $this->settingsService->updateDefaultSupervisorLimits($validated['supervisor_limits']);
        $evaluatorLimits = $this->settingsService->updateDefaultEvaluatorLimits($validated['evaluator_limits']);
        $evaluatorVisibility = $this->settingsService->updateEvaluatorVisibility($validated['evaluator_visibility'] ?? []);

        return $this->success([
            'team_limits' => $teamLimits,
            'default_supervisor_limits' => $supervisorLimits,
            'default_evaluator_limits' => $evaluatorLimits,
            'evaluator_visibility' => $evaluatorVisibility,
        ], 'Proposal settings updated.');
    }

    public function myProject(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->hasRole('student')) {
            return $this->error('Only students can access their project.', 403);
        }

        $project = $this->projectService->findStudentProject($user);

        if ($project) {
            $this->workflowService->loadProject($project);
        }

        $registration = $this->workflowService->registrationStatus($user);

        return $this->success([
            'project' => $project ? new ProjectResource($project) : null,
            'can_register_proposal' => $project ? false : $registration['can_register_proposal'],
            'registration' => $registration,
        ]);
    }

    public function eligibleMembers(Request $request): JsonResponse
    {
        $project = $request->project_id ? Project::find($request->project_id) : null;

        if ($request->boolean('transferable')) {
            if (! $project || ! FypProposal::canManageProjectTeamFor($request->user(), $project)) {
                return $this->error('Unauthorized.', 403);
            }

            return $this->success(UserResource::collection(
                $this->workflowService->getTransferableMembers($project, $request->search)
            ));
        }

        if ($project && (int) $project->student_id !== (int) $request->user()?->id && ! FypProposal::canManageProjectTeam($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        return $this->success(UserResource::collection(
            $this->workflowService->getEligibleMembers(
                $project,
                $request->search,
                $request->user()
            )
        ));
    }

    public function inviteMembers(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'invitee_ids' => ['required', 'array', 'min:1'],
            'invitee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        try {
            $updated = $this->workflowService->inviteMembers(
                $request->user(),
                $project,
                $validated['invitee_ids']
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Invitations sent.');
    }

    public function addTeamMembers(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['integer', 'exists:users,id'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $updated = $this->workflowService->addTeamMembersDirectly(
                $request->user(),
                $project,
                $validated['user_ids'],
                $validated['comments'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Team members added.');
    }

    public function cancelInvitation(Request $request, Project $project, ProjectInvitation $invitation): JsonResponse
    {
        $validated = $request->validate([
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $updated = $this->workflowService->cancelInvitation(
                $request->user(),
                $project,
                $invitation,
                $validated['comments'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Invitation cancelled.');
    }

    public function acceptInvitationOnBehalf(Request $request, Project $project, ProjectInvitation $invitation): JsonResponse
    {
        $validated = $request->validate([
            'comments' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $updated = $this->workflowService->acceptInvitationOnBehalf(
                $request->user(),
                $project,
                $invitation,
                $validated['comments'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Invitation accepted on behalf of the student.');
    }

    public function pendingInvitations(Request $request): JsonResponse
    {
        $invitations = $this->workflowService->getPendingInvitations($request->user());

        return $this->success(ProjectInvitationResource::collection($invitations));
    }

    public function submit(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('student')) {
            return $this->error('Only students can submit proposals.', 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'area_of_specialization' => ['nullable', 'string', 'max:255'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'proposal_file' => ['required', 'file', 'mimes:pdf', 'max:'.config('fyp.proposal.document_max_kb', 10240)],
            'supervisor_id' => ['required', 'exists:users,id'],
            'invitee_ids' => ['required', 'array', 'min:1'],
            'invitee_ids.*' => ['integer', 'exists:users,id'],
        ]);

        try {
            $project = $this->workflowService->submitProposal($request->user(), $validated);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($project), 'Proposal submitted and invitations sent.', 201);
    }

    public function respondInvitation(Request $request, ProjectInvitation $invitation): JsonResponse
    {
        $validated = $request->validate([
            'response' => ['required', 'in:accepted,rejected'],
            'comments' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $project = $this->workflowService->respondInvitation(
                $request->user(),
                $invitation,
                $validated['response'],
                $validated['comments'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($project), 'Invitation response saved.');
    }

    public function supervisorRespond(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'accept' => ['required', 'boolean'],
            'feedback' => ['nullable', 'string'],
        ]);

        try {
            $updated = $this->workflowService->supervisorRespond(
                $request->user(),
                $project,
                $validated['accept'],
                $validated['feedback'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Supervisor response recorded.');
    }

    public function supervisorRequestRevision(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'feedback' => ['required', 'string'],
        ]);

        try {
            $updated = $this->workflowService->supervisorRequestRevision(
                $request->user(),
                $project,
                $validated['feedback']
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Proposal returned to students for revision.');
    }

    public function supervisorRevisionReview(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'proceed' => ['required', 'boolean'],
            'feedback' => ['nullable', 'string'],
        ]);

        try {
            $updated = $this->workflowService->supervisorRevisionReview(
                $request->user(),
                $project,
                $validated['proceed'],
                $validated['feedback'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(
            new ProjectResource($updated),
            $validated['proceed'] ? 'Revised proposal approved to proceed.' : 'Revised proposal returned to students.'
        );
    }

    public function changeSupervisor(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'supervisor_id' => ['required', 'exists:users,id'],
        ]);

        try {
            $updated = $this->workflowService->changeSupervisor(
                $request->user(),
                $project,
                $validated['supervisor_id']
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Supervisor updated.');
    }

    public function assignEvaluators(Request $request, Project $project): JsonResponse
    {
        if (! FypProposal::canAssignEvaluators($request->user())) {
            return $this->error('Unauthorized to update evaluators.', 403);
        }

        if (! $this->projectService->canAccessProject($request->user(), $project)) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'evaluator_ids' => [
                'required',
                'array',
                'min:'.FypProposal::minEvaluators(),
                'max:'.FypProposal::maxEvaluators(),
            ],
            'evaluator_ids.*' => ['integer', 'exists:users,id'],
            'evaluator_resubmit_mode' => ['nullable', 'in:all,negative_only'],
        ]);

        try {
            $updated = $this->workflowService->assignEvaluators(
                $request->user(),
                $project,
                $validated['evaluator_ids'],
                $validated['evaluator_resubmit_mode'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Evaluators assigned.');
    }

    public function evaluatorReview(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'decision' => ['required', 'in:accepted,rejected,revision_required'],
            'comments' => ['required', 'string'],
            'evaluator_id' => ['nullable', 'integer', 'exists:users,id'],
            'answers' => ['required', 'array', 'min:1'],
            'answers.*.question_id' => ['required', 'integer'],
            'answers.*.marks' => ['required', 'integer', 'min:0'],
            'answers.*.comment' => ['nullable', 'string'],
        ]);

        try {
            $updated = $this->workflowService->submitEvaluatorReview(
                $request->user(),
                $project,
                $validated['decision'],
                $validated['comments'] ?? null,
                $validated['evaluator_id'] ?? null,
                $validated['answers']
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Evaluation submitted.');
    }

    public function resubmit(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'proposal_file' => ['required', 'file', 'mimes:pdf', 'max:'.config('fyp.proposal.document_max_kb', 10240)],
        ]);

        try {
            $updated = $this->workflowService->resubmitProposal($request->user(), $project, $validated);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Revised proposal sent to supervisor for review.');
    }

    public function committeeFinal(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'comments' => ['nullable', 'string'],
            'evaluator_resubmit_mode' => ['nullable', 'in:all,negative_only'],
        ]);

        try {
            $updated = $this->workflowService->committeeFinalReview(
                $request->user(),
                $project,
                $validated['approve'],
                $validated['comments'] ?? null,
                $validated['evaluator_resubmit_mode'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Committee review saved.');
    }

    public function committeeHeadApprove(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'comments' => ['nullable', 'string'],
            'evaluator_resubmit_mode' => ['nullable', 'in:all,negative_only'],
        ]);

        try {
            $updated = $this->workflowService->committeeHeadApprove(
                $request->user(),
                $project,
                $validated['approve'],
                $validated['comments'] ?? null,
                $validated['evaluator_resubmit_mode'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Final decision recorded.');
    }

    public function returnToTeamFormation(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'comments' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $updated = $this->workflowService->returnToTeamFormation(
                $request->user(),
                $project,
                $validated['comments'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Proposal returned to team formation stage.');
    }

    public function workflowLogs(Project $project): JsonResponse
    {
        $logs = $project->workflowLogs()->with('actor')->chronological()->get();

        return $this->success(ProposalWorkflowLogResource::collection($logs));
    }

    public function evaluators(Request $request): JsonResponse
    {
        if (! FypProposal::canAssignEvaluators($request->user())) {
            return $this->error('Unauthorized to manage evaluators.', 403);
        }

        $evaluators = User::role('evaluator')
            ->where('status', 'active')
            ->when($request->filled('project_id'), function ($query) use ($request) {
                $project = Project::query()->find($request->integer('project_id'));

                if ($project?->supervisor_id) {
                    $query->where('id', '!=', $project->supervisor_id);
                }

                if ($project?->program_id) {
                    $query->where(function ($inner) use ($project) {
                        $inner->where('program_id', $project->program_id)
                            ->orWhereHas('programMemberships', fn ($membership) => $membership->where('program_id', $project->program_id));
                    });
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        return $this->success(UserResource::collection($evaluators));
    }
}
