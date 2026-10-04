<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ProposalSession;
use App\Services\AutoEvaluatorAssignmentService;
use App\Services\ProgramScopeService;
use App\Support\FypProposal;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AutoEvaluatorAssignmentController extends Controller
{
    use ApiResponse;

    public function __construct(
        private AutoEvaluatorAssignmentService $autoAssignmentService,
        private ProgramScopeService $programScope,
    ) {}

    public function sessions(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! FypProposal::canAssignEvaluators($user)) {
            return $this->error('Unauthorized.', 403);
        }

        $query = ProposalSession::query()->where('status', 'active');

        if (! $this->programScope->isGlobalAdmin($user)) {
            $accessible = $this->programScope->accessibleProgramIds($user) ?? [];
            $query->whereIn('program_id', $accessible);
        }

        $sessions = $query->orderByDesc('id')->get(['id', 'name', 'code', 'program_id']);

        return $this->success($sessions);
    }

    protected function validatePayload(Request $request): array
    {
        return $request->validate([
            'proposal_session_id' => ['required', 'integer', 'exists:proposal_sessions,id'],
            'phase' => ['required', 'in:proposal,phase_1,phase_2'],
            'evaluators_per_project' => ['nullable', 'integer', 'min:1', 'max:10'],
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        if (! FypProposal::canAssignEvaluators($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $this->validatePayload($request);
        $session = ProposalSession::findOrFail($validated['proposal_session_id']);

        try {
            $result = $this->autoAssignmentService->preview(
                $request->user(),
                $session,
                $validated['phase'],
                $validated['evaluators_per_project'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success($result);
    }

    public function assign(Request $request): JsonResponse
    {
        if (! FypProposal::canAssignEvaluators($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $this->validatePayload($request);
        $session = ProposalSession::findOrFail($validated['proposal_session_id']);

        try {
            $result = $this->autoAssignmentService->autoAssign(
                $request->user(),
                $session,
                $validated['phase'],
                $validated['evaluators_per_project'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success($result, "{$result['assigned_count']} project(s) assigned evaluators.");
    }

    public function pending(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! FypProposal::canAssignEvaluators($user)) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'phase' => ['required', 'in:proposal,phase_1,phase_2'],
            'proposal_session_id' => ['nullable', 'integer', 'exists:proposal_sessions,id'],
        ]);

        $session = isset($validated['proposal_session_id'])
            ? ProposalSession::find($validated['proposal_session_id'])
            : null;

        $programIds = $this->programScope->isGlobalAdmin($user)
            ? null
            : ($this->programScope->accessibleProgramIds($user) ?? []);

        $projects = $this->autoAssignmentService->pendingProjectsForPhase($session, $validated['phase'], $programIds);

        return $this->success([
            'projects' => collect($projects->items())->map(fn ($project) => [
                'id' => $project->id,
                'title' => $project->title,
                'leader_name' => $project->student?->name,
                'leader_email' => $project->student?->email,
                'supervisor_name' => $project->supervisor?->name,
                'supervisor_email' => $project->supervisor?->email,
            ]),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
                'total' => $projects->total(),
            ],
        ]);
    }
}
