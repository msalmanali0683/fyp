<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\ProjectMember;
use App\Models\User;
use App\Services\ActivityLogService;
use App\Services\ProjectService;
use App\Services\SupervisorCapacityService;
use App\Support\EffectiveWorkflow;
use App\Support\FypPhases;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProjectController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ProjectService $projectService,
        private SupervisorCapacityService $supervisorCapacity,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->projectService->canListProjects($user)) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(array_keys(config('fyp.project_statuses', [])))],
            'phase' => ['nullable', Rule::in(FypPhases::slugs())],
            'workflow_stage' => ['nullable', 'string', 'max:64'],
            'needs_action' => ['nullable', Rule::in([
                'committee_review',
                'committee_final',
                'committee_head',
                'supervisor',
                'evaluator',
                'student_revision',
            ])],
            'supervisor_id' => ['nullable', 'integer', 'exists:users,id'],
            'evaluator_id' => ['nullable', 'integer', 'exists:users,id'],
            'proposal_session_id' => ['nullable', 'integer', 'exists:proposal_sessions,id'],
            'list_context' => ['nullable', Rule::in(['all', 'supervisor', 'evaluator'])],
            'evaluator_scope' => ['nullable', Rule::in(['assigned', 'completed'])],
            'visibility' => ['nullable', Rule::in(['active', 'trashed'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $listContext = $validated['list_context'] ?? 'all';
        $evaluatorScope = $validated['evaluator_scope'] ?? null;

        if ($listContext === 'supervisor' && ! $user->hasRole('supervisor')) {
            return $this->error('Unauthorized.', 403);
        }

        if ($listContext === 'evaluator') {
            if (! $user->hasRole('evaluator')) {
                return $this->error('Unauthorized.', 403);
            }

            if (! $evaluatorScope) {
                $evaluatorScope = 'assigned';
            }
        } elseif ($evaluatorScope) {
            return $this->error('Unauthorized.', 403);
        }

        if ($listContext === 'all' && ! $this->projectService->hasFullProjectListAccess($user)) {
            return $this->error('Unauthorized.', 403);
        }

        $visibility = $validated['visibility'] ?? 'active';
        if ($visibility === 'trashed' && ! $this->projectService->canManageDeletedProjects($user)) {
            return $this->error('Unauthorized.', 403);
        }

        $query = Project::with([
            'student',
            'supervisor',
            'phases.reviewer',
            'members.user',
            'invitations.invitee',
            'evaluators.evaluator',
            'evaluatorReviews.evaluator',
            'workflowLogs.actor',
            'activeSupervisorChangeRequest.currentSupervisor',
            'activeSupervisorChangeRequest.newSupervisor',
            'activeSupervisorChangeRequest.requestedBy',
        ]);

        if ($visibility === 'trashed') {
            $query->onlyTrashed();
        }

        $this->projectService->scopeProjectsForUser($query, $user, $listContext === 'all' ? null : $listContext);
        $this->projectService->applyProjectFilters($query, $request, $user);
        $this->projectService->applyEvaluatorScopeFilter($query, $user, $evaluatorScope, $listContext);

        $projects = $query->latest()->paginate($request->integer('per_page', 15));

        return $this->success([
            'projects' => ProjectResource::collection($projects),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
                'trashed_count' => $this->projectService->canManageDeletedProjects($user)
                    ? Project::onlyTrashed()->count()
                    : 0,
            ],
            'filters' => [
                'phases' => FypPhases::all(),
                'statuses' => config('fyp.project_statuses', []),
                'workflow_stages' => config('fyp.project_workflow_stages', []),
                'deliverable_workflow_stages' => EffectiveWorkflow::filterStageOptions('phase_1'),
                'needs_action_options' => $this->needsActionOptions($user),
            ],
        ]);
    }

    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $user = $request->user();

        if (! $this->projectService->hasFullProjectListAccess($user)) {
            return $this->error('Unauthorized.', 403);
        }

        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in(array_keys(config('fyp.project_statuses', [])))],
            'phase' => ['nullable', Rule::in(FypPhases::slugs())],
            'workflow_stage' => ['nullable', 'string', 'max:64'],
            'needs_action' => ['nullable', Rule::in([
                'committee_review',
                'committee_final',
                'committee_head',
                'supervisor',
                'evaluator',
                'student_revision',
            ])],
            'supervisor_id' => ['nullable', 'integer', 'exists:users,id'],
            'evaluator_id' => ['nullable', 'integer', 'exists:users,id'],
            'proposal_session_id' => ['nullable', 'integer', 'exists:proposal_sessions,id'],
        ]);

        $query = Project::with(['student', 'supervisor', 'phases'])
            ->where('status', '!=', 'deleted');

        $this->projectService->scopeProjectsForUser($query, $user);
        $this->projectService->applyProjectFilters($query, $request, $user);

        $filename = 'fyp-projects-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($query) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['ID', 'Title', 'Leader', 'SAP ID', 'Supervisor', 'Phase', 'Workflow Stage', 'Status']);

            $query->latest()->chunk(200, function ($projects) use ($handle) {
                foreach ($projects as $project) {
                    fputcsv($handle, [
                        $project->id,
                        $project->title,
                        $project->student?->name,
                        $project->student?->registration_no,
                        $project->supervisor?->name,
                        FypPhases::label($project->current_phase),
                        EffectiveWorkflow::stageLabel($project),
                        $project->status,
                    ]);
                }
            });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->hasRole('student')) {
            return $this->error('Only students can register an FYP project.', 403);
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'supervisor_id' => ['nullable', 'exists:users,id'],
        ]);

        try {
            $project = $this->projectService->createProject($request->user(), $validated);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        ActivityLogService::log('create', 'projects', "Registered FYP project {$project->title}", null, $project->id);

        return $this->success(new ProjectResource($project), 'FYP project registered.', 201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        if (! $this->projectService->canAccessProject($request->user(), $project)) {
            return $this->error('Unauthorized.', 403);
        }

        $project->load([
            'student',
            'supervisor',
            'phases.reviewer',
            'members.user',
            'invitations.invitee',
            'evaluators.evaluator',
            'evaluatorReviews.evaluator',
            'workflowLogs.actor',
            'activeSupervisorChangeRequest.currentSupervisor',
            'activeSupervisorChangeRequest.newSupervisor',
            'activeSupervisorChangeRequest.requestedBy',
        ]);

        return $this->success(new ProjectResource($project));
    }

    public function destroy(Request $request, Project $project): JsonResponse
    {
        if (! $this->projectService->canAccessProject($request->user(), $project)) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->projectService->deleteProject($request->user(), $project, $validated['comments'] ?? null);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(null, 'Project deleted and team dissolved.');
    }

    public function forceDestroy(Request $request, int $project): JsonResponse
    {
        if (! $this->projectService->canManageDeletedProjects($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        $projectModel = Project::onlyTrashed()->findOrFail($project);

        try {
            $this->projectService->forceDeleteProject($request->user(), $projectModel);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(null, 'Project permanently deleted.');
    }

    public function removeMember(Request $request, Project $project, ProjectMember $member): JsonResponse
    {
        if (! $this->projectService->canAccessProject($request->user(), $project)) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $updated = $this->projectService->removeTeamMember(
                $request->user(),
                $project,
                $member,
                $validated['comments'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Team member removed.');
    }

    public function transferLeadership(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'new_leader_id' => ['required', 'integer', 'exists:users,id'],
            'comments' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $updated = $this->projectService->transferLeadership(
                $request->user(),
                $project,
                (int) $validated['new_leader_id'],
                $validated['comments'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Team leader updated.');
    }

    public function committeeHeadApproveAll(Request $request): JsonResponse
    {
        try {
            $result = $this->projectService->committeeHeadApproveAll($request->user());
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success($result, "{$result['approved_count']} item(s) approved.");
    }

    public function update(Request $request, Project $project): JsonResponse
    {
        $user = $request->user();
        $canManage = $user->hasAnyRole(['admin', 'fyp-committee-head'])
            || ($user->hasRole('student') && $project->student_id === $user->id);

        if (! $canManage) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'academic_year' => ['nullable', 'string', 'max:20'],
            'supervisor_id' => ['nullable', 'exists:users,id'],
            'status' => ['nullable', Rule::in(array_keys(config('fyp.project_statuses', [])))],
        ]);

        if ($user->hasRole('student')) {
            unset($validated['status'], $validated['supervisor_id']);
        }

        $project->update($validated);

        ActivityLogService::log('update', 'projects', "Updated FYP project {$project->title}", null, $project->id);

        return $this->success(
            new ProjectResource($project->fresh()->load(['student', 'supervisor', 'phases.reviewer'])),
            'Project updated.'
        );
    }

    public function supervisors(Request $request): JsonResponse
    {
        $excludeProjectId = $request->integer('project_id') ?: null;
        $excludeEvaluatorIds = collect();
        $project = null;

        if ($excludeProjectId) {
            $project = Project::query()->find($excludeProjectId);
            if ($project) {
                $excludeEvaluatorIds = $project->evaluators()->pluck('evaluator_id');
            }
        }

        $programId = $project?->program_id ?? $request->user()?->program_id;

        $supervisors = $this->supervisorCapacity
            ->availableSupervisors('proposal', $excludeProjectId, $programId)
            ->reject(fn (User $supervisor) => $excludeEvaluatorIds->contains($supervisor->id))
            ->map(fn (User $supervisor) => [
                'id' => $supervisor->id,
                'name' => $supervisor->name,
                'email' => $supervisor->email,
                'remaining_capacity' => $this->supervisorCapacity->remainingCapacity($supervisor, 'proposal'),
            ])
            ->values();

        return $this->success($supervisors);
    }

    protected function needsActionOptions(User $user): array
    {
        $options = [];

        if ($user->hasAnyRole(config('fyp.dashboard_admin_roles', []))) {
            $options['committee_review'] = 'Committee review queue';
            $options['committee_final'] = 'Committee final queue';
            if ($user->hasRole('fyp-committee-head')) {
                $options['committee_head'] = 'Committee head approval';
            }
        }

        if ($user->hasRole('supervisor')) {
            $options['supervisor'] = 'Needs supervisor action';
        }

        if ($user->hasRole('evaluator')) {
            $options['evaluator'] = 'Needs my evaluation';
        }

        if ($user->hasRole('student')) {
            $options['student_revision'] = 'Needs my revision';
        }

        return $options;
    }
}
