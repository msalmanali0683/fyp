<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluatorReview extends Model
{
    protected $fillable = [
        'project_id',
        'evaluator_id',
        'fyp_phase',
        'decision',
        'marks',
        'max_marks',
        'comments',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'marks' => 'integer',
            'max_marks' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(EvaluatorReviewAnswer::class);
    }
}
