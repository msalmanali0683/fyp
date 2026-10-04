<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluatorReviewAnswerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'question_id' => $this->question_id,
            'question_text' => $this->whenLoaded('question', fn () => $this->question->text),
            'max_marks' => $this->whenLoaded('question', fn () => $this->question->max_marks),
            'marks_awarded' => $this->marks_awarded,
            'comment' => $this->comment,
        ];
    }
}
