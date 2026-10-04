<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\EvaluationQuestionResource;
use App\Models\EvaluationQuestion;
use App\Services\QuestionBankService;
use App\Support\FypProposal;
use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class QuestionBankController extends Controller
{
    use ApiResponse;

    public function __construct(private QuestionBankService $questionBank) {}

    public function index(Request $request): JsonResponse
    {
        if ($request->filled('phase')) {
            $validated = $request->validate(['phase' => ['required', 'in:proposal,phase_1,phase_2']]);

            return $this->success(
                EvaluationQuestionResource::collection($this->questionBank->activeQuestionsForPhase($validated['phase']))
            );
        }

        $grouped = $this->questionBank->listAllGroupedByPhase();

        return $this->success([
            'proposal' => EvaluationQuestionResource::collection($grouped['proposal']),
            'phase_1' => EvaluationQuestionResource::collection($grouped['phase_1']),
            'phase_2' => EvaluationQuestionResource::collection($grouped['phase_2']),
            'can_manage' => FypProposal::canManageQuestionBank($request->user()),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'phase' => ['required', 'in:proposal,phase_1,phase_2'],
            'text' => ['required', 'string', 'max:2000'],
            'max_marks' => ['required', 'integer', 'min:1', 'max:1000'],
            'order' => ['nullable', 'integer', 'min:0'],
        ]);

        try {
            $question = $this->questionBank->create(
                $request->user(),
                $validated['phase'],
                $validated['text'],
                (int) $validated['max_marks'],
                isset($validated['order']) ? (int) $validated['order'] : null
            );
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new EvaluationQuestionResource($question), 'Question added.', 201);
    }

    public function update(Request $request, EvaluationQuestion $question): JsonResponse
    {
        $validated = $request->validate([
            'text' => ['sometimes', 'string', 'max:2000'],
            'max_marks' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'order' => ['sometimes', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        try {
            $updated = $this->questionBank->update($request->user(), $question, $validated);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(new EvaluationQuestionResource($updated), 'Question updated.');
    }

    public function destroy(Request $request, EvaluationQuestion $question): JsonResponse
    {
        try {
            $this->questionBank->delete($request->user(), $question);
        } catch (ValidationException $e) {
            return $this->error($e->getMessage(), 422, $e->errors());
        }

        return $this->success(null, 'Question deleted.');
    }
}
