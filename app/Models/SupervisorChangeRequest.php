<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupervisorChangeRequest extends Model
{
    protected $fillable = [
        'project_id',
        'requested_by',
        'current_supervisor_id',
        'new_supervisor_id',
        'reason',
        'current_supervisor_status',
        'current_supervisor_comments',
        'current_supervisor_responded_by',
        'new_supervisor_status',
        'new_supervisor_comments',
        'new_supervisor_responded_by',
        'authority_status',
        'authority_comments',
        'authority_decided_by',
        'overall_status',
    ];

    public const ACTIVE_STATUSES = ['pending', 'awaiting_authority'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function currentSupervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'current_supervisor_id');
    }

    public function newSupervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'new_supervisor_id');
    }

    public function isActive(): bool
    {
        return in_array($this->overall_status, self::ACTIVE_STATUSES, true);
    }
}
