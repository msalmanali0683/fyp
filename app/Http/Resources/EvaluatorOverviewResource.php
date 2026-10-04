<?php

namespace App\Http\Resources;

use App\Services\EvaluatorCapacityService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvaluatorOverviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $stats = $this->overview_stats ?? [];
        $capacity = app(EvaluatorCapacityService::class);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'status' => $this->status,
            'program_id' => $this->program_id,
            'program_name' => $this->relationLoaded('programRelation')
                ? $this->programRelation?->name
                : $this->program,
            'proposal' => $stats['proposal'] ?? ['pending' => 0, 'complete' => 0, 'total' => 0],
            'phase_1' => $stats['phase_1'] ?? ['pending' => 0, 'complete' => 0, 'total' => 0],
            'phase_2' => $stats['phase_2'] ?? ['pending' => 0, 'complete' => 0, 'total' => 0],
            'total_pending' => (int) ($stats['total_pending'] ?? 0),
            'total_complete' => (int) ($stats['total_complete'] ?? 0),
            'total_assignments' => (int) ($stats['total_assignments'] ?? 0),
            'evaluation_limit_proposal' => $this->evaluation_limit_proposal,
            'evaluation_limit_phase_1' => $this->evaluation_limit_phase_1,
            'evaluation_limit_phase_2' => $this->evaluation_limit_phase_2,
            'effective_limit_proposal' => $capacity->limitFor($this->resource, 'proposal'),
            'effective_limit_phase_1' => $capacity->limitFor($this->resource, 'phase_1'),
            'effective_limit_phase_2' => $capacity->limitFor($this->resource, 'phase_2'),
        ];
    }
}
