<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupervisorOverviewResource extends JsonResource
{
    public function toArray(Request $request): array
    {
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
            'proposal_groups' => (int) ($this->proposal_groups_count ?? 0),
            'phase_1_groups' => (int) ($this->phase_1_groups_count ?? 0),
            'phase_2_groups' => (int) ($this->phase_2_groups_count ?? 0),
            'total_groups' => (int) ($this->total_groups_count ?? 0),
            'supervision_limit_proposal' => $this->supervision_limit_proposal,
            'supervision_limit_phase_1' => $this->supervision_limit_phase_1,
            'supervision_limit_phase_2' => $this->supervision_limit_phase_2,
            'effective_limit_proposal' => app(\App\Services\SupervisorCapacityService::class)->limitFor($this->resource, 'proposal'),
            'effective_limit_phase_1' => app(\App\Services\SupervisorCapacityService::class)->limitFor($this->resource, 'phase_1'),
            'effective_limit_phase_2' => app(\App\Services\SupervisorCapacityService::class)->limitFor($this->resource, 'phase_2'),
        ];
    }
}
