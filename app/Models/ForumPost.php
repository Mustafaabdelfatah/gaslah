<?php

namespace App\Models;

use App\Enum\Forum\ForumStatusEnum;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ForumPost extends BaseModel
{
    protected $fillable = ['thread_id', 'author_id', 'body', 'status'];

    protected $casts = ['status' => ForumStatusEnum::class];

    public function thread(): BelongsTo
    {
        return $this->belongsTo(ForumThread::class, 'thread_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
