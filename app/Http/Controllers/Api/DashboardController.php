<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\DashboardService;
use App\Services\ProjectService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    use ApiResponse;

    public function __construct(
        private DashboardService $dashboardService,
        private ProjectService $projectService,
    ) {
    }

    public function stats(Request $request): JsonResponse
    {
        if ($response = $this->authorizeDashboard($request)) {
            return $response;
        }

        $user = $request->user();
        $hasFullProjectList = $this->projectService->hasFullProjectListAccess($user);

        return $this->success(
            $this->dashboardService->stats($user, $hasFullProjectList)
        );
    }

    public function charts(Request $request): JsonResponse
    {
        if ($response = $this->authorizeDashboard($request)) {
            return $response;
        }

        $user = $request->user();
        $hasFullProjectList = $this->projectService->hasFullProjectListAccess($user);

        return $this->success(
            $this->dashboardService->charts($user, $hasFullProjectList)
        );
    }

    public function recentRecords(Request $request): JsonResponse
    {
        return $this->recentProjects($request);
    }

    public function recentProjects(Request $request): JsonResponse
    {
        if ($response = $this->authorizeDashboard($request)) {
            return $response;
        }

        $user = $request->user();

        $query = Project::with(['student', 'supervisor', 'phases', 'members.user'])->latest();
        $this->projectService->scopeProjectsForUser($query, $user);

        $projects = $query->limit(5)->get();

        return $this->success(ProjectResource::collection($projects));
    }

    public function nextActions(Request $request): JsonResponse
    {
        if ($response = $this->authorizeDashboard($request)) {
            return $response;
        }

        return $this->success([
            'actions' => $this->dashboardService->nextActions($request->user()),
        ]);
    }

    public function roleWidgets(Request $request): JsonResponse
    {
        if ($response = $this->authorizeDashboard($request)) {
            return $response;
        }

        return $this->success([
            'widgets' => $this->dashboardService->roleWidgets($request->user()),
        ]);
    }

    protected function authorizeDashboard(Request $request): ?JsonResponse
    {
        if (! $request->user()?->can('view dashboard')) {
            return $this->error('Unauthorized.', 403);
        }

        return null;
    }
}
