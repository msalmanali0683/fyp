<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProgramMembership extends Model
{
    protected $fillable = [
        'user_id',
        'program_id',
        'is_committee_head',
        'is_committee_member',
        'is_primary',
    ];

    protected function casts(): array
    {
        return [
            'is_committee_head' => 'boolean',
            'is_committee_member' => 'boolean',
            'is_primary' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class);
    }
}
