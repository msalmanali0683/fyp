<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\PhaseDeliverableWorkflowService;
use App\Services\ProjectService;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PhaseDeliverableWorkflowController extends Controller
{
    use ApiResponse;

    public function __construct(
        private PhaseDeliverableWorkflowService $phaseWorkflow,
        private ProjectService $projectService,
    ) {}

    public function supervisorRespond(Request $request, Project $project, string $phase): JsonResponse
    {
        if (! $this->projectService->canAccessProject($request->user(), $project)) {
            return $this->error('Unauthorized.', 403);
        }

        $validated = $request->validate([
            'accept' => ['required', 'boolean'],
            'feedback' => ['nullable', 'string'],
        ]);

        try {
            $updated = $this->phaseWorkflow->supervisorRespond(
                $request->user(),
                $project,
                $phase,
                (bool) $validated['accept'],
                $validated['feedback'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Supervisor review saved.');
    }

    public function supervisorRevisionReview(Request $request, Project $project, string $phase): JsonResponse
    {
        $validated = $request->validate([
            'proceed' => ['required', 'boolean'],
            'feedback' => ['nullable', 'string'],
        ]);

        try {
            $updated = $this->phaseWorkflow->supervisorRevisionReview(
                $request->user(),
                $project,
                $phase,
                (bool) $validated['proceed'],
                $validated['feedback'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Supervisor revision review saved.');
    }

    public function keepEvaluators(Request $request, Project $project, string $phase): JsonResponse
    {
        try {
            $updated = $this->phaseWorkflow->keepExistingEvaluators($request->user(), $project, $phase);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Existing evaluators confirmed.');
    }

    public function assignEvaluators(Request $request, Project $project, string $phase): JsonResponse
    {
        $validated = $request->validate([
            'evaluator_ids' => ['required', 'array', 'min:1'],
            'evaluator_ids.*' => ['integer', 'exists:users,id'],
            'evaluator_resubmit_mode' => ['nullable', 'string'],
        ]);

        try {
            $updated = $this->phaseWorkflow->assignEvaluators(
                $request->user(),
                $project,
                $phase,
                $validated['evaluator_ids'],
                $validated['evaluator_resubmit_mode'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Evaluators assigned.');
    }

    public function returnFromCommittee(Request $request, Project $project, string $phase): JsonResponse
    {
        $validated = $request->validate([
            'comments' => ['nullable', 'string'],
        ]);

        try {
            $updated = $this->phaseWorkflow->returnFromCommitteeReview(
                $request->user(),
                $project,
                $phase,
                $validated['comments'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Deliverable returned to students.');
    }

    public function evaluatorReview(Request $request, Project $project, string $phase): JsonResponse
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
            $updated = $this->phaseWorkflow->submitEvaluatorReview(
                $request->user(),
                $project,
                $phase,
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

    public function resubmit(Request $request, Project $project, string $phase): JsonResponse
    {
        try {
            $updated = $this->phaseWorkflow->resubmitDeliverable($request->user(), $project, $phase);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Revised deliverable submitted.');
    }

    public function allowRepeatCarryForward(Request $request, Project $project, string $phase): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string'],
        ]);

        try {
            $updated = $this->phaseWorkflow->allowCarryForward(
                $request->user(),
                $project,
                $phase,
                $validated['notes'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Student approved to continue in the next available session.');
    }

    public function requireRepeatResubmission(Request $request, Project $project, string $phase): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['required', 'string'],
        ]);

        try {
            $this->phaseWorkflow->markResubmissionRequired(
                $request->user(),
                $project,
                $phase,
                $validated['notes']
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(null, 'Student has been notified that a new proposal is required.');
    }

    public function committeeFinal(Request $request, Project $project, string $phase): JsonResponse
    {
        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'comments' => ['nullable', 'string'],
            'evaluator_resubmit_mode' => ['nullable', 'string'],
        ]);

        try {
            $updated = $this->phaseWorkflow->committeeFinalReview(
                $request->user(),
                $project,
                $phase,
                (bool) $validated['approve'],
                $validated['comments'] ?? null,
                $validated['evaluator_resubmit_mode'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Committee final review saved.');
    }

    public function committeeHeadApprove(Request $request, Project $project, string $phase): JsonResponse
    {
        $validated = $request->validate([
            'approve' => ['required', 'boolean'],
            'comments' => ['nullable', 'string'],
            'evaluator_resubmit_mode' => ['nullable', 'string'],
        ]);

        try {
            $updated = $this->phaseWorkflow->committeeHeadApprove(
                $request->user(),
                $project,
                $phase,
                (bool) $validated['approve'],
                $validated['comments'] ?? null,
                $validated['evaluator_resubmit_mode'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Committee head decision saved.');
    }

    public function reevaluate(Request $request, Project $project, string $phase): JsonResponse
    {
        $validated = $request->validate([
            'keep_same_evaluators' => ['required', 'boolean'],
            'evaluator_ids' => ['required_if:keep_same_evaluators,false', 'array'],
            'evaluator_ids.*' => ['integer', 'exists:users,id'],
            'extended_deadline' => ['required', 'date', 'after_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        try {
            $updated = $this->phaseWorkflow->reevaluate(
                $request->user(),
                $project,
                $phase,
                (bool) $validated['keep_same_evaluators'],
                $validated['evaluator_ids'] ?? [],
                $validated['extended_deadline'],
                $validated['notes'] ?? null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new ProjectResource($updated), 'Reevaluation started.');
    }
}
