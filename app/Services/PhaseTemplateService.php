<?php

namespace App\Services;

use App\Models\PhaseTemplate;
use App\Models\User;
use App\Support\FypProposal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PhaseTemplateService
{
    protected const ALLOWED_EXTENSIONS = ['pdf', 'doc', 'docx'];

    public function disk(): string
    {
        return config('fyp.template_disk', 'public');
    }

    public function maxKilobytes(): int
    {
        return (int) config('fyp.template_max_kb', 10240);
    }

    public function listGroupedByPhase(): array
    {
        $templates = PhaseTemplate::with('uploader')->latest()->get();

        return [
            'proposal' => $templates->where('phase', 'proposal')->values(),
            'phase_1' => $templates->where('phase', 'phase_1')->values(),
            'phase_2' => $templates->where('phase', 'phase_2')->values(),
        ];
    }

    public function listForPhase(string $phase): Collection
    {
        $this->assertValidPhase($phase);

        return PhaseTemplate::with('uploader')
            ->where('phase', $phase)
            ->latest()
            ->get();
    }

    public function upload(User $actor, string $phase, UploadedFile $file, ?string $title, ?string $description): PhaseTemplate
    {
        if (! FypProposal::canManagePhaseTemplates($actor)) {
            throw ValidationException::withMessages(['template' => ['Unauthorized to manage phase templates.']]);
        }

        $this->assertValidPhase($phase);
        $this->assertValidFile($file);

        $directory = "phase-templates/{$phase}";
        $extension = strtolower($file->getClientOriginalExtension());
        $filename = Str::slug($title ?: pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)).'-'.now()->format('YmdHis').'-'.Str::uuid().'.'.$extension;
        $path = $file->storeAs($directory, $filename, $this->disk());

        if (! $path) {
            throw ValidationException::withMessages(['file' => ['Failed to save the template file. Please try again.']]);
        }

        return PhaseTemplate::create([
            'phase' => $phase,
            'title' => $title ?: $file->getClientOriginalName(),
            'description' => $description,
            'file_path' => $path,
            'original_filename' => $file->getClientOriginalName(),
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'uploaded_by' => $actor->id,
        ]);
    }

    public function delete(User $actor, PhaseTemplate $template): void
    {
        if (! FypProposal::canManagePhaseTemplates($actor)) {
            throw ValidationException::withMessages(['template' => ['Unauthorized to manage phase templates.']]);
        }

        if ($template->file_path && Storage::disk($this->disk())->exists($template->file_path)) {
            Storage::disk($this->disk())->delete($template->file_path);
        }

        $template->delete();
    }

    public function url(PhaseTemplate $template): string
    {
        return Storage::disk($this->disk())->url($template->file_path);
    }

    protected function assertValidPhase(string $phase): void
    {
        if (! in_array($phase, ['proposal', 'phase_1', 'phase_2'], true)) {
            throw ValidationException::withMessages(['phase' => ['Invalid phase selected.']]);
        }
    }

    protected function assertValidFile(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => ['Only PDF and Word (doc/docx) files are allowed.'],
            ]);
        }

        if ($file->getSize() > $this->maxKilobytes() * 1024) {
            throw ValidationException::withMessages([
                'file' => ['The file must not exceed '.$this->maxKilobytes().'KB.'],
            ]);
        }
    }
}
