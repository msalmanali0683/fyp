<?php

namespace App\Models;

use App\Services\ProgramScopeService;
use App\Support\FypProposal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProjectQuery extends Model
{
    protected $fillable = [
        'project_id',
        'raised_by',
        'raised_by_role',
        'subject',
        'status',
        'closed_at',
        'closed_by',
    ];

    protected function casts(): array
    {
        return [
            'closed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ProjectQueryMessage::class)->orderBy('created_at')->orderBy('id');
    }

    public function scopeStatus(Builder $query, string $status): Builder
    {
        return $query->where('status', $status);
    }

    public function scopeChronological(Builder $query): Builder
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }

    public function isVisibleTo(User $user): bool
    {
        if ((int) $this->raised_by === (int) $user->id) {
            return true;
        }

        if (in_array($this->raised_by_role, ['student', 'staff'], true)) {
            $studentProject = $user->studentProject();

            if ($studentProject && (int) $studentProject->id === (int) $this->project_id) {
                return true;
            }
        }

        if (FypProposal::canRespondProjectQueries($user)) {
            $this->loadMissing('project');

            return app(ProgramScopeService::class)->canAccessProject($user, $this->project);
        }

        return false;
    }
}
