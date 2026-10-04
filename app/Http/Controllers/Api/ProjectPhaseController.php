<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectPhaseResource;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\ProjectPhase;
use App\Services\ActivityLogService;
use App\Services\ProjectService;
use App\Services\ProposalSessionService;
use App\Support\FypPhases;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectPhaseController extends Controller
{
    use ApiResponse;

    public function __construct(
        private ProjectService $projectService,
        private ProposalSessionService $sessionService,
    ) {}

    public function update(Request $request, Project $project, string $phase): JsonResponse
    {
        if (! $this->projectService->canAccessProject($request->user(), $project)) {
            return $this->error('Unauthorized.', 403);
        }

        if ((int) $project->student_id !== (int) $request->user()->id) {
            return $this->error('Only the project leader can edit phase submissions.', 403);
        }

        if (! in_array($phase, FypPhases::slugs(), true)) {
            return $this->error('Invalid phase.', 404);
        }

        $projectPhase = $this->findPhase($project, $phase);

        $validated = $request->validate([
            'content' => ['nullable', 'string'],
            'attachment' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $updated = $this->projectService->updatePhaseContent($projectPhase, $validated);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        ActivityLogService::log('update', 'project_phases', "Updated {$phase} submission for {$project->title}", null, $project->id);

        return $this->success(new ProjectPhaseResource($updated), 'Phase submission saved.');
    }

    public function uploadAttachment(Request $request, Project $project, string $phase): JsonResponse
    {
        if ((int) $project->student_id !== (int) $request->user()->id) {
            return $this->error('Only the project leader can upload phase files.', 403);
        }

        if (! in_array($phase, ['phase_1', 'phase_2'], true)) {
            return $this->error('Invalid phase.', 404);
        }

        $projectPhase = $this->findPhase($project, $phase);

        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        try {
            $this->sessionService->assertCanEditPhaseDeliverable($request->user(), $project, $phase);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        if (! $projectPhase->isEditable()) {
            return $this->error('This phase cannot be edited in its current workflow stage.', 422);
        }

        $path = $validated['file']->store("projects/{$project->id}/phases/{$phase}", 'public');

        $projectPhase->update(['attachment' => $path]);

        ActivityLogService::log('update', 'project_phases', "Uploaded {$phase} file for {$project->title}", null, $project->id);

        return $this->success(new ProjectPhaseResource($projectPhase->fresh()), 'Phase file uploaded.');
    }

    public function submit(Request $request, Project $project, string $phase): JsonResponse
    {
        if ((int) $project->student_id !== (int) $request->user()->id) {
            return $this->error('Only the project leader can submit phases.', 403);
        }

        if (! in_array($phase, FypPhases::slugs(), true)) {
            return $this->error('Invalid phase.', 404);
        }

        $projectPhase = $this->findPhase($project, $phase);

        try {
            $submitted = $this->projectService->submitPhase($projectPhase);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        ActivityLogService::log('submit', 'project_phases', "Submitted {$phase} for {$project->title}", null, $project->id);

        return $this->success(new ProjectPhaseResource($submitted), 'Phase submitted for review.');
    }

    public function review(Request $request, Project $project, string $phase): JsonResponse
    {
        if (! $this->projectService->canReviewPhase($request->user(), $project)) {
            return $this->error('You are not authorized to review this phase.', 403);
        }

        if (! in_array($phase, FypPhases::slugs(), true)) {
            return $this->error('Invalid phase.', 404);
        }

        $validated = $request->validate([
            'status' => ['required', Rule::in(['under_review', 'approved', 'revision_required', 'rejected'])],
            'feedback' => ['nullable', 'string'],
        ]);

        $projectPhase = $this->findPhase($project, $phase);

        try {
            $reviewed = $this->projectService->reviewPhase(
                $projectPhase,
                $request->user(),
                $validated['status'],
                $validated['feedback'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        ActivityLogService::log('review', 'project_phases', "Reviewed {$phase} as {$validated['status']} for {$project->title}", null, $project->id);

        return $this->success([
            'phase' => new ProjectPhaseResource($reviewed),
            'project' => new ProjectResource($reviewed->project->load(['student', 'supervisor', 'phases.reviewer'])),
        ], 'Phase review saved.');
    }

    private function findPhase(Project $project, string $phase): ProjectPhase
    {
        $projectPhase = $project->phases()->where('phase', $phase)->first();

        if (! $projectPhase) {
            abort(404, 'Phase not found.');
        }

        return $projectPhase;
    }
}
