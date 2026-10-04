<?php

namespace App\Http\Resources;

use App\Support\FypProposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectInvitationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $project = $this->relationLoaded('project') ? $this->project : null;

        return [
            'id' => $this->id,
            'status' => $this->status,
            'responded_at' => $this->responded_at?->toDateTimeString(),
            'response_comments' => $this->response_comments,
            'project' => new ProjectResource($this->whenLoaded('project')),
            'inviter' => new UserResource($this->whenLoaded('inviter')),
            'invitee' => new UserResource($this->whenLoaded('invitee')),
            'can_cancel' => $this->status === 'pending'
                && $project
                && FypProposal::canCancelInvitation($user, $project),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
