<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationQuestion extends Model
{
    protected $fillable = [
        'phase',
        'text',
        'max_marks',
        'order',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'max_marks' => 'integer',
            'order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function answers(): HasMany
    {
        return $this->hasMany(EvaluatorReviewAnswer::class, 'question_id');
    }
}
