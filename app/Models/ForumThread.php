<?php

namespace App\Models;

use App\Enum\Forum\ForumStatusEnum;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ForumThread extends BaseModel
{
    protected $fillable = [
        'organization_id', 'category_id', 'title', 'slug', 'body', 'author_id',
        'status', 'rejection_reason', 'is_pinned', 'is_closed', 'reply_count', 'view_count', 'last_activity_at',
    ];

    protected $casts = [
        'status' => ForumStatusEnum::class,
        'is_pinned' => 'boolean',
        'is_closed' => 'boolean',
        'reply_count' => 'integer',
        'view_count' => 'integer',
        'last_activity_at' => 'datetime',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(ForumCategory::class, 'category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function posts(): HasMany
    {
        return $this->hasMany(ForumPost::class, 'thread_id');
    }
}
