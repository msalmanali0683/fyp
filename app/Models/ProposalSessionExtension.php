<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProposalSessionExtension extends Model
{
    protected $fillable = [
        'proposal_session_id',
        'user_id',
        'extended_initial_deadline',
        'extended_final_deadline',
        'reason',
        'granted_by',
    ];

    protected function casts(): array
    {
        return [
            'extended_initial_deadline' => 'datetime',
            'extended_final_deadline' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ProposalSession::class, 'proposal_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
