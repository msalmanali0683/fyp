<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentTransferRequest extends Model
{
    protected $fillable = [
        'student_id',
        'from_project_id',
        'to_project_id',
        'reason',
        'status',
        'decided_by',
        'decision_comments',
        'decided_at',
    ];

    protected function casts(): array
    {
        return [
            'decided_at' => 'datetime',
        ];
    }

    /**
     * pending         -> awaiting FYP office/admin approval to look for a new group
     * eligible        -> approved; student may browse and request to join a same-phase group
     * pending_leader  -> a specific group has been requested; awaiting that group's leader (or
     *                    admin/committee/supervisor acting on the leader's behalf)
     * approved        -> leader accepted; membership moved (terminal)
     * rejected        -> FYP office declined the initial request (terminal)
     * cancelled       -> withdrawn by the student or authority (terminal)
     */
    public const ACTIVE_STATUSES = ['pending', 'eligible', 'pending_leader'];

    public function student(): BelongsTo
    {
        return $this->belongsTo(User::class, 'student_id');
    }

    public function fromProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'from_project_id');
    }

    public function toProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'to_project_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE_STATUSES, true);
    }
}
