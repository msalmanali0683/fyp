<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluatorReviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fyp_phase' => $this->fyp_phase,
            'decision' => $this->decision,
            'marks' => $this->marks,
            'max_marks' => $this->max_marks,
            'comments' => $this->comments,
            'answers' => EvaluatorReviewAnswerResource::collection($this->whenLoaded('answers')),
            'reviewed_at' => $this->reviewed_at?->toDateTimeString(),
            'evaluator' => new UserResource($this->whenLoaded('evaluator')),
        ];
    }
}
