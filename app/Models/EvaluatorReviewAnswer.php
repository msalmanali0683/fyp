<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EvaluatorReviewAnswer extends Model
{
    protected $fillable = [
        'evaluator_review_id',
        'question_id',
        'marks_awarded',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'marks_awarded' => 'integer',
        ];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(EvaluatorReview::class, 'evaluator_review_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(EvaluationQuestion::class, 'question_id');
    }
}
