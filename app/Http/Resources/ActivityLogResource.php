<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ActivityLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'module' => $this->module,
            'description' => $this->description,
            'ip_address' => $this->ip_address,
            'user' => $this->when($this->relationLoaded('user'), fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
                'roles' => $this->user->getRoleNames(),
            ] : null),
            'project' => $this->when($this->relationLoaded('project'), fn () => $this->project ? [
                'id' => $this->project->id,
                'title' => $this->project->title,
            ] : null),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}
