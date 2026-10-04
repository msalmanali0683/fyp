<?php

namespace App\Http\Resources;

use App\Support\FypPhases;
use App\Support\FypProposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectPhaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phase' => $this->phase,
            'phase_label' => FypPhases::label($this->phase),
            'content' => $this->content,
            'attachment' => $this->attachment,
            'attachment_url' => $this->attachment
                ? '/storage/'.ltrim(str_replace('\\', '/', $this->attachment), '/')
                : null,
            'status' => $this->status,
            'status_label' => FypPhases::statusLabel($this->status),
            'workflow_stage' => $this->workflow_stage ?? 'draft',
            'workflow_stage_label' => FypPhases::isDeliverablePhase($this->phase)
                ? FypProposal::deliverableStageLabel(FypProposal::displayDeliverableStage($this->workflow_stage ?? 'draft'))
                : FypProposal::stageLabel($this->status),
            'feedback' => $this->feedback,
            'submitted_at' => $this->submitted_at?->toDateTimeString(),
            'reviewed_at' => $this->reviewed_at?->toDateTimeString(),
            'is_editable' => $this->isEditable(),
            'certificate_available' => $this->status === 'approved'
                && (
                    ! FypPhases::isDeliverablePhase($this->phase)
                    || ($this->workflow_stage ?? 'draft') === 'approved'
                ),
            'reviewer' => new UserResource($this->whenLoaded('reviewer')),
            'can_reevaluate' => FypPhases::isDeliverablePhase($this->phase)
                && $this->status === 'approved'
                && FypProposal::canReevaluatePhase($request->user()),
            'is_reevaluation' => (bool) $this->is_reevaluation,
            'reevaluation_deadline' => $this->reevaluation_deadline?->toDateTimeString(),
            'reevaluated_at' => $this->reevaluated_at?->toDateTimeString(),
            'reevaluator' => new UserResource($this->whenLoaded('reevaluator')),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
