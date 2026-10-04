<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Http\Resources\SupervisorOverviewResource;
use App\Models\Project;
use App\Models\User;
use App\Services\FypSettingsService;
use App\Services\ProgramScopeService;
use App\Services\SupervisorCapacityService;
use App\Support\FypProposal;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupervisorController extends Controller
{
    use ApiResponse;

    public function __construct(
        private SupervisorCapacityService $supervisorCapacity,
        private FypSettingsService $settingsService,
        private ProgramScopeService $programScope,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! FypProposal::canViewSupervisorOverview($request->user())) {
            return $this->error('Unauthorized.', 403);
        }

        $query = User::role('supervisor')
            ->with('programRelation')
            ->when($request->search, fn ($q) => $q->where(function ($query) use ($request) {
                $search = $request->search;
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            }))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->program_id, fn ($q) => $q->where('program_id', $request->integer('program_id')));

        if (! $this->programScope->isGlobalAdmin($request->user())) {
            $this->programScope->scopeUsers($query, $request->user());
        }

        $supervisors = $query
            ->withCount([
                'supervisedProjects as proposal_groups_count' => fn ($q) => $q
                    ->where('current_phase', 'proposal')
                    ->where('status', 'active'),
                'supervisedProjects as phase_1_groups_count' => fn ($q) => $q
                    ->where('current_phase', 'phase_1')
                    ->where('status', 'active'),
                'supervisedProjects as phase_2_groups_count' => fn ($q) => $q
                    ->where('current_phase', 'phase_2')
                    ->where('status', 'active'),
                'supervisedProjects as total_groups_count' => fn ($q) => $q
                    ->where('status', 'active'),
            ])
            ->orderBy('name')
            ->get();

        return $this->success(SupervisorOverviewResource::collection($supervisors));
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $actor = $request->user();

        if (! FypProposal::canViewSupervisorOverview($actor)) {
            return $this->error('Unauthorized.', 403);
        }

        if (! $user->hasRole('supervisor')) {
            return $this->error('This user is not a supervisor.', 404);
        }

        if (! $this->programScope->isGlobalAdmin($actor)) {
            $scoped = User::query()->whereKey($user->id);
            $this->programScope->scopeUsers($scoped, $actor);

            if (! $scoped->exists()) {
                return $this->error('Unauthorized.', 403);
            }
        }

        $user->loadMissing('programRelation');
        $user->loadCount([
            'supervisedProjects as proposal_groups_count' => fn ($q) => $q->where('current_phase', 'proposal')->where('status', 'active'),
            'supervisedProjects as phase_1_groups_count' => fn ($q) => $q->where('current_phase', 'phase_1')->where('status', 'active'),
            'supervisedProjects as phase_2_groups_count' => fn ($q) => $q->where('current_phase', 'phase_2')->where('status', 'active'),
            'supervisedProjects as total_groups_count' => fn ($q) => $q->where('status', 'active'),
        ]);

        $projectRelations = ['student', 'supervisor', 'phases', 'members', 'evaluators', 'activeSupervisorChangeRequest'];

        $pendingTasks = Project::query()
            ->where('supervisor_id', $user->id)
            ->where('status', 'active')
            ->whereSupervisorNeedsAction()
            ->with($projectRelations)
            ->get();

        $projects = Project::query()
            ->where('supervisor_id', $user->id)
            ->with($projectRelations)
            ->latest('id')
            ->paginate($request->integer('per_page', 20));

        return $this->success([
            'supervisor' => new SupervisorOverviewResource($user),
            'pending_tasks' => ProjectResource::collection($pendingTasks),
            'projects' => ProjectResource::collection($projects->items()),
            'meta' => [
                'current_page' => $projects->currentPage(),
                'last_page' => $projects->lastPage(),
                'per_page' => $projects->perPage(),
                'total' => $projects->total(),
            ],
        ]);
    }

    public function updateSupervisionLimits(Request $request, User $user): JsonResponse
    {
        if (! FypProposal::canManageProposalSettings($request->user())) {
            return $this->error('Unauthorized to manage supervision limits.', 403);
        }

        if (! $user->hasRole('supervisor')) {
            return $this->error('User is not a supervisor.', 422);
        }

        $validated = $request->validate([
            'supervision_limit_proposal' => ['nullable', 'integer', 'min:0', 'max:100'],
            'supervision_limit_phase_1' => ['nullable', 'integer', 'min:0', 'max:100'],
            'supervision_limit_phase_2' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $user->update($validated);

        return $this->success(new SupervisorOverviewResource(
            $user->fresh()->loadCount([
                'supervisedProjects as proposal_groups_count' => fn ($q) => $q->where('current_phase', 'proposal')->where('status', 'active'),
                'supervisedProjects as phase_1_groups_count' => fn ($q) => $q->where('current_phase', 'phase_1')->where('status', 'active'),
                'supervisedProjects as phase_2_groups_count' => fn ($q) => $q->where('current_phase', 'phase_2')->where('status', 'active'),
                'supervisedProjects as total_groups_count' => fn ($q) => $q->where('status', 'active'),
            ])
        ), 'Supervision limits updated.');
    }
}
