<?php

namespace App\Services;

use App\Models\EvaluationQuestion;
use App\Models\EvaluatorReviewAnswer;
use App\Models\User;
use App\Support\FypProposal;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class QuestionBankService
{
    public function listAllGroupedByPhase(): array
    {
        $questions = EvaluationQuestion::with('creator')->orderBy('order')->orderBy('id')->get();

        return [
            'proposal' => $questions->where('phase', 'proposal')->values(),
            'phase_1' => $questions->where('phase', 'phase_1')->values(),
            'phase_2' => $questions->where('phase', 'phase_2')->values(),
        ];
    }

    public function activeQuestionsForPhase(string $phase): Collection
    {
        $this->assertValidPhase($phase);

        return EvaluationQuestion::where('phase', $phase)
            ->where('is_active', true)
            ->orderBy('order')
            ->orderBy('id')
            ->get();
    }

    public function totalMarksForPhase(string $phase): int
    {
        return (int) $this->activeQuestionsForPhase($phase)->sum('max_marks');
    }

    public function create(User $actor, string $phase, string $text, int $maxMarks, ?int $order = null): EvaluationQuestion
    {
        $this->assertCanManage($actor);
        $this->assertValidPhase($phase);
        $this->assertValidMaxMarks($maxMarks);

        return EvaluationQuestion::create([
            'phase' => $phase,
            'text' => $text,
            'max_marks' => $maxMarks,
            'order' => $order ?? ((int) EvaluationQuestion::where('phase', $phase)->max('order') + 1),
            'is_active' => true,
            'created_by' => $actor->id,
        ]);
    }

    public function update(User $actor, EvaluationQuestion $question, array $data): EvaluationQuestion
    {
        $this->assertCanManage($actor);

        if (array_key_exists('max_marks', $data)) {
            $this->assertValidMaxMarks((int) $data['max_marks']);
        }

        $question->update(array_intersect_key($data, array_flip(['text', 'max_marks', 'order', 'is_active'])));

        return $question->fresh();
    }

    public function delete(User $actor, EvaluationQuestion $question): void
    {
        $this->assertCanManage($actor);

        if (EvaluatorReviewAnswer::where('question_id', $question->id)->exists()) {
            throw ValidationException::withMessages([
                'question' => ['This question already has evaluator answers recorded — deactivate it instead of deleting so past evaluations stay intact.'],
            ]);
        }

        $question->delete();
    }

    public function toggleActive(User $actor, EvaluationQuestion $question, bool $active): EvaluationQuestion
    {
        $this->assertCanManage($actor);

        $question->update(['is_active' => $active]);

        return $question->fresh();
    }

    protected function assertCanManage(User $actor): void
    {
        if (! FypProposal::canManageQuestionBank($actor)) {
            throw ValidationException::withMessages(['question' => ['Unauthorized to manage the evaluation question bank.']]);
        }
    }

    protected function assertValidPhase(string $phase): void
    {
        if (! in_array($phase, ['proposal', 'phase_1', 'phase_2'], true)) {
            throw ValidationException::withMessages(['phase' => ['Invalid phase selected.']]);
        }
    }

    protected function assertValidMaxMarks(int $maxMarks): void
    {
        if ($maxMarks < 1 || $maxMarks > 1000) {
            throw ValidationException::withMessages(['max_marks' => ['Max marks must be between 1 and 1000.']]);
        }
    }
}
