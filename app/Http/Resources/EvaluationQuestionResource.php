<?php

namespace App\Http\Resources;

use App\Support\FypPhases;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluationQuestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phase' => $this->phase,
            'phase_label' => FypPhases::label($this->phase),
            'text' => $this->text,
            'max_marks' => $this->max_marks,
            'order' => $this->order,
            'is_active' => $this->is_active,
            'created_by' => new UserResource($this->whenLoaded('creator')),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
