<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalWorkflowLog extends Model
{
    protected $fillable = [
        'project_id',
        'fyp_phase',
        'stage',
        'action',
        'actor_id',
        'actor_role',
        'comments',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function scopeChronological($query)
    {
        return $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
