<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectQueryResource;
use App\Models\ProjectQuery;
use App\Services\ProjectQueryService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ProjectQueryController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ProjectQueryService $queryService,
    ) {}

    public function myProjects(Request $request): JsonResponse
    {
        $projects = $this->queryService->queryableProjectsFor($request->user())
            ->map(fn ($project) => [
                'id' => $project->id,
                'title' => $project->title,
                'student_name' => $project->student?->name,
            ])
            ->values();

        return $this->success($projects);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'status' => ['nullable', 'string', 'in:open,answered,closed'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = $this->queryService->listFor($request->user(), $validated);

        return $this->success([
            'queries' => ProjectQueryResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(Request $request, ProjectQuery $projectQuery): JsonResponse
    {
        try {
            $this->queryService->assertCanView($request->user(), $projectQuery);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        $projectQuery->load(['raiser', 'closer', 'messages.author', 'project.supervisor:id,name']);

        return $this->success(new ProjectQueryResource($projectQuery));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'project_id' => ['nullable', 'integer', 'exists:projects,id'],
            'subject' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string'],
        ]);

        try {
            $query = $this->queryService->createQuery(
                $request->user(),
                $validated['project_id'] ?? null,
                $validated['subject'],
                $validated['message']
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectQueryResource($query), 'Query submitted.', 201);
    }

    public function reply(Request $request, ProjectQuery $projectQuery): JsonResponse
    {
        $validated = $request->validate([
            'message' => ['required', 'string'],
        ]);

        try {
            $query = $this->queryService->reply($request->user(), $projectQuery, $validated['message']);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectQueryResource($query), 'Reply posted.');
    }

    public function close(Request $request, ProjectQuery $projectQuery): JsonResponse
    {
        try {
            $query = $this->queryService->close($request->user(), $projectQuery);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectQueryResource($query), 'Query closed.');
    }

    public function reopen(Request $request, ProjectQuery $projectQuery): JsonResponse
    {
        try {
            $query = $this->queryService->reopen($request->user(), $projectQuery);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectQueryResource($query), 'Query reopened.');
    }

    public function downloadPdf(Request $request, ProjectQuery $projectQuery): Response|JsonResponse
    {
        try {
            $this->queryService->assertCanDownload($request->user(), $projectQuery);
            $pdf = $this->queryService->generatePdf($projectQuery);
            $fileName = $this->queryService->fileName($projectQuery);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$fileName.'"',
        ]);
    }
}
