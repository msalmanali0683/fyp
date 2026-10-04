<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EvaluatorOverviewResource;
use App\Models\User;
use App\Services\EvaluatorOverviewService;
use App\Services\ProgramScopeService;
use App\Support\FypProposal;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvaluatorController extends Controller
{
    use ApiResponse;

    public function __construct(
        private EvaluatorOverviewService $overviewService,
        private ProgramScopeService $programScope,
    ) {}

    public function updateEvaluationLimits(Request $request, User $user): JsonResponse
    {
        if (! FypProposal::canManageProposalSettings($request->user())) {
            return $this->error('Unauthorized to manage evaluation limits.', 403);
        }

        if (! $user->hasRole('evaluator')) {
            return $this->error('User is not an evaluator.', 422);
        }

        $validated = $request->validate([
            'evaluation_limit_proposal' => ['nullable', 'integer', 'min:0', 'max:100'],
            'evaluation_limit_phase_1' => ['nullable', 'integer', 'min:0', 'max:100'],
            'evaluation_limit_phase_2' => ['nullable', 'integer', 'min:0', 'max:100'],
        ]);

        $user->update($validated);

        $updated = $user->fresh();
        $statsMap = $this->overviewService->buildStatsMap(collect([$updated]));
        $updated->overview_stats = $this->overviewService->statsFor($updated, $statsMap);

        return $this->success(new EvaluatorOverviewResource($updated), 'Evaluation limits updated.');
    }

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $this->canViewOverview($user)) {
            return $this->error('Unauthorized.', 403);
        }

        $query = User::role('evaluator')
            ->with('programRelation')
            ->when($request->search, fn ($q) => $q->where(function ($query) use ($request) {
                $search = $request->search;
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            }))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->program_id, fn ($q) => $q->where('program_id', $request->integer('program_id')));

        if (! $this->programScope->isGlobalAdmin($user)) {
            $this->programScope->scopeUsers($query, $user);
        }

        $evaluators = $query
            ->orderBy('name')
            ->get();

        $statsMap = $this->overviewService->buildStatsMap($evaluators);

        $evaluators->each(function ($evaluator) use ($statsMap) {
            $evaluator->overview_stats = $this->overviewService->statsFor($evaluator, $statsMap);
        });

        return $this->success(EvaluatorOverviewResource::collection($evaluators));
    }

    protected function canViewOverview(User $user): bool
    {
        return FypProposal::canAssignEvaluators($user);
    }
}
