<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class ProposalSessionReport extends Model
{
    public const TYPE_WORKFLOW_PDF = 'workflow_pdf';

    public const TYPE_STUDENTS_EXCEL = 'students_excel';

    public const TYPE_PROJECTS_EXCEL = 'projects_excel';

    protected $fillable = [
        'proposal_session_id',
        'lifecycle_phase',
        'report_type',
        'batch_key',
        'file_path',
        'file_name',
        'file_size',
        'generated_by',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'generated_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ProposalSession::class, 'proposal_session_id');
    }

    public function generator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_WORKFLOW_PDF => 'Project Workflow Report (PDF)',
            self::TYPE_STUDENTS_EXCEL => 'Session Students List (Excel)',
            self::TYPE_PROJECTS_EXCEL => 'Session Projects Summary (Excel)',
            default => ucwords(str_replace('_', ' ', $type)),
        };
    }

    public function downloadUrl(): ?string
    {
        if (! Storage::disk('public')->exists($this->file_path)) {
            return null;
        }

        return "/api/proposal-sessions/{$this->proposal_session_id}/reports/{$this->id}/download";
    }
}
