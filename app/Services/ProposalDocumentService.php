<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ProposalDocumentService
{
    public function disk(): string
    {
        return config('fyp.proposal.document_disk', 'public');
    }

    public function directory(int $projectId): string
    {
        return 'proposals/'.$projectId;
    }

    public function store(UploadedFile $file, int $projectId): array
    {
        $this->assertPdf($file);

        $directory = $this->directory($projectId);
        $filename = 'proposal-'.now()->format('YmdHis').'-'.Str::uuid().'.pdf';
        $path = $file->storeAs($directory, $filename, $this->disk());

        if (! $path) {
            throw ValidationException::withMessages([
                'proposal_file' => ['Failed to save the proposal PDF. Please try again.'],
            ]);
        }

        return [
            'path' => $path,
            'original_name' => $file->getClientOriginalName(),
            'url' => Storage::disk($this->disk())->url($path),
        ];
    }

    public function replace(UploadedFile $file, int $projectId, ?string $existingPath = null): array
    {
        if ($existingPath) {
            $this->delete($existingPath);
        }

        return $this->store($file, $projectId);
    }

    public function delete(?string $path): void
    {
        if ($path && Storage::disk($this->disk())->exists($path)) {
            Storage::disk($this->disk())->delete($path);
        }
    }

    public function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return Storage::disk($this->disk())->url($path);
    }

    protected function assertPdf(UploadedFile $file): void
    {
        if ($file->getClientOriginalExtension() !== 'pdf' && $file->getMimeType() !== 'application/pdf') {
            throw ValidationException::withMessages([
                'proposal_file' => ['Only PDF files are allowed.'],
            ]);
        }
    }
}
