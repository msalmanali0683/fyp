<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhaseTemplate extends Model
{
    protected $fillable = [
        'phase',
        'title',
        'description',
        'file_path',
        'original_filename',
        'file_size',
        'mime_type',
        'uploaded_by',
    ];

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
