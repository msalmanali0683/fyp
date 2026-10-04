<?php

namespace App\Http\Resources;

use App\Services\PhaseTemplateService;
use App\Support\FypPhases;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PhaseTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'phase' => $this->phase,
            'phase_label' => FypPhases::label($this->phase),
            'title' => $this->title,
            'description' => $this->description,
            'original_filename' => $this->original_filename,
            'file_size' => $this->file_size,
            'file_size_label' => $this->formatSize($this->file_size),
            'mime_type' => $this->mime_type,
            'download_url' => app(PhaseTemplateService::class)->url($this->resource),
            'uploaded_by' => new UserResource($this->whenLoaded('uploader')),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    protected function formatSize(?int $bytes): string
    {
        if (! $bytes) {
            return '0 KB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 1).' MB';
        }

        return round($bytes / 1024, 1).' KB';
    }
}
