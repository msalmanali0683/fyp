<?php

namespace App\Http\Resources;

use App\Models\ProposalSessionReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProposalSessionReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'lifecycle_phase' => $this->lifecycle_phase,
            'lifecycle_phase_label' => $this->lifecyclePhaseLabel(),
            'report_type' => $this->report_type,
            'report_type_label' => ProposalSessionReport::typeLabel($this->report_type),
            'batch_key' => $this->batch_key,
            'file_name' => $this->file_name,
            'file_size' => $this->file_size,
            'download_url' => $this->downloadUrl(),
            'generated_at' => $this->generated_at?->toDateTimeString(),
            'generated_by' => $this->whenLoaded('generator', fn () => $this->generator?->name),
        ];
    }

    protected function lifecyclePhaseLabel(): string
    {
        return match ($this->lifecycle_phase) {
            'proposal_phase' => 'Proposal Phase',
            'phase_1' => 'Phase 1',
            'phase_2' => 'Phase 2',
            default => ucwords(str_replace('_', ' ', $this->lifecycle_phase)),
        };
    }
}
