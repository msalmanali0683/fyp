<?php

namespace App\Models;

use App\Support\FypPhases;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectPhase extends Model
{
    protected $fillable = [
        'project_id',
        'phase',
        'content',
        'attachment',
        'status',
        'workflow_stage',
        'feedback',
        'submitted_at',
        'reviewed_at',
        'reviewed_by',
        'is_reevaluation',
        'reevaluation_deadline',
        'reevaluated_by',
        'reevaluated_at',
    ];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'is_reevaluation' => 'boolean',
            'reevaluation_deadline' => 'datetime',
            'reevaluated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function reevaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reevaluated_by');
    }

    public function isEditable(): bool
    {
        if (FypPhases::isDeliverablePhase($this->phase)) {
            return in_array($this->workflow_stage ?? 'draft', ['draft', 'revision_required'], true);
        }

        return in_array($this->status, ['draft', 'revision_required'], true);
    }
}
