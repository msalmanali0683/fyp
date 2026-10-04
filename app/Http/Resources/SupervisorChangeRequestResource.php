<?php

namespace App\Http\Resources;

use App\Support\FypProposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupervisorChangeRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project' => $this->when($this->relationLoaded('project') && $this->project, fn () => [
                'id' => $this->project->id,
                'title' => $this->project->title,
            ]),
            'reason' => $this->reason,
            'overall_status' => $this->overall_status,
            'current_supervisor_status' => $this->current_supervisor_status,
            'current_supervisor_comments' => $this->current_supervisor_comments,
            'new_supervisor_status' => $this->new_supervisor_status,
            'new_supervisor_comments' => $this->new_supervisor_comments,
            'authority_status' => $this->authority_status,
            'authority_comments' => $this->authority_comments,
            'requested_by' => new UserResource($this->whenLoaded('requestedBy')),
            'current_supervisor' => new UserResource($this->whenLoaded('currentSupervisor')),
            'new_supervisor' => new UserResource($this->whenLoaded('newSupervisor')),
            'viewer_can_respond_as_current_supervisor' => FypProposal::canRespondSupervisorChangeAsCurrent($user, $this->resource),
            'viewer_can_respond_as_new_supervisor' => FypProposal::canRespondSupervisorChangeAsNew($user, $this->resource),
            'viewer_can_decide' => FypProposal::canDecideActiveSupervisorChange($user, $this->resource),
            'viewer_can_manage' => FypProposal::canManageSupervisorChange($user, $this->resource),
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
