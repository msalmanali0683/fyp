<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectQueryMessage extends Model
{
    protected $fillable = [
        'project_query_id',
        'author_id',
        'author_role',
        'message',
    ];

    public function projectQuery(): BelongsTo
    {
        return $this->belongsTo(ProjectQuery::class, 'project_query_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
