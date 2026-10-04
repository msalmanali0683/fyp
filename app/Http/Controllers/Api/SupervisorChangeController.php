<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\SupervisorChangeRequestResource;
use App\Models\Project;
use App\Models\SupervisorChangeRequest;
use App\Services\ProposalWorkflowService;
use App\Services\SupervisorChangeService;
use App\Support\FypProposal;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SupervisorChangeController extends Controller
{
    use ApiResponse;

    public function __construct(
        private SupervisorChangeService $supervisorChange,
        private ProposalWorkflowService $workflowService,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! FypProposal::canManageSupervisorInvitations($user) && ! FypProposal::canDecideSupervisorChange($user)) {
            return $this->error('Unauthorized.', 403);
        }

        $requests = $this->supervisorChange->listActiveRequestsForUser($user);

        return $this->success(SupervisorChangeRequestResource::collection($requests));
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'new_supervisor_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $this->supervisorChange->requestSupervisorChange(
                $request->user(),
                $project,
                (int) $validated['new_supervisor_id'],
                $validated['reason']
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success($this->projectPayload($project), 'Supervisor change request submitted.', 201);
    }

    public function respond(Request $request, Project $project, SupervisorChangeRequest $changeRequest): JsonResponse
    {
        if ($changeRequest->project_id !== $project->id) {
            return $this->error('Supervisor change request not found for this project.', 404);
        }

        $validated = $request->validate([
            'accept' => ['required', 'boolean'],
            'which' => ['nullable', 'in:current,new'],
            'comments' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->supervisorChange->respondToSupervisorChange(
                $request->user(),
                $changeRequest,
                $validated['which'] ?? null,
                (bool) $validated['accept'],
                $validated['comments'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success($this->projectPayload($project), 'Supervisor change response recorded.');
    }

    public function decide(Request $request, Project $project, SupervisorChangeRequest $changeRequest): JsonResponse
    {
        if ($changeRequest->project_id !== $project->id) {
            return $this->error('Supervisor change request not found for this project.', 404);
        }

        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'comments' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->supervisorChange->authorityDecide(
                $request->user(),
                $changeRequest,
                (bool) $validated['approve'],
                $validated['comments'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success($this->projectPayload($project), 'Supervisor change decision recorded.');
    }

    public function cancel(Request $request, Project $project, SupervisorChangeRequest $changeRequest): JsonResponse
    {
        if ($changeRequest->project_id !== $project->id) {
            return $this->error('Supervisor change request not found for this project.', 404);
        }

        $validated = $request->validate([
            'comments' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $this->supervisorChange->cancelSupervisorChange(
                $request->user(),
                $changeRequest,
                $validated['comments'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success($this->projectPayload($project), 'Supervisor change request cancelled.');
    }

    protected function projectPayload(Project $project): ProjectResource
    {
        return new ProjectResource($this->workflowService->loadProject($project->fresh()));
    }
}
