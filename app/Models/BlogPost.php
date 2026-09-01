<?php

namespace App\Models;

use App\Enum\Blog\BlogPostStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class BlogPost extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id', 'title', 'slug', 'excerpt', 'content', 'cover_image_url',
        'tags', 'status', 'published_at', 'created_by_id',
    ];

    protected $casts = [
        'status' => BlogPostStatusEnum::class,
        'tags' => 'array',
        'published_at' => 'datetime',
        'view_count' => 'integer',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(BlogCategory::class, 'category_id');
    }

    /**
     * What a reader is allowed to see: published, and not scheduled for later.
     */
    public function scopeReadable(Builder $query): Builder
    {
        return $query
            ->where('status', BlogPostStatusEnum::Published->value)
            ->whereNotNull('published_at')
            ->where('published_at', '<=', Carbon::now());
    }
}
