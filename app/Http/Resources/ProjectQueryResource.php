<?php

namespace App\Http\Resources;

use App\Services\ProjectQueryService;
use App\Support\FypProposal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectQueryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $service = app(ProjectQueryService::class);
        $canParticipate = $user && $service->canParticipate($user, $this->resource);
        $canRespond = $user && FypProposal::canRespondProjectQueries($user);

        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'project' => $this->when($this->relationLoaded('project') && $this->project, fn () => [
                'id' => $this->project->id,
                'title' => $this->project->title,
                'supervisor' => $this->project->relationLoaded('supervisor') && $this->project->supervisor
                    ? ['id' => $this->project->supervisor->id, 'name' => $this->project->supervisor->name]
                    : null,
            ]),
            'subject' => $this->subject,
            'status' => $this->status,
            'status_label' => ucfirst($this->status),
            'raised_by_role' => $this->raised_by_role,
            'raiser' => new UserResource($this->whenLoaded('raiser')),
            'closed_at' => $this->closed_at?->toDateTimeString(),
            'closer' => new UserResource($this->whenLoaded('closer')),
            'messages' => ProjectQueryMessageResource::collection($this->whenLoaded('messages')),
            'message_count' => $this->when(
                $this->relationLoaded('messages'),
                fn () => $this->messages->count()
            ),
            'can_reply' => $canParticipate && $this->status !== 'closed',
            'can_close' => $canParticipate && $this->status !== 'closed',
            'can_reopen' => $canRespond && $this->status === 'closed',
            'can_download' => $canParticipate,
            'created_at' => $this->created_at?->toDateTimeString(),
            'updated_at' => $this->updated_at?->toDateTimeString(),
        ];
    }
}
