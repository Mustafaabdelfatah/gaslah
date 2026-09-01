<?php

namespace App\Services\Blog;

use App\Enum\Blog\BlogPostStatusEnum;
use App\Models\BlogCategory;
use App\Models\BlogPost;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Writing and publishing an article.
 *
 * Two rules live here rather than in the controller, because the create and the edit path
 * must not disagree about them: a slug is the reader's address so it has to be unique, and
 * the publish date is stamped once — re-publishing an archived article does not pretend it
 * was written today.
 */
class BlogPostService
{
    /**
     * How many "-2, -3, …" suffixes to try before falling back to a timestamp.
     */
    private const SLUG_ATTEMPTS = 50;

    public function create(array $attributes, ?BlogPostStatusEnum $status, ?string $slugSource, User $author): BlogPost
    {
        $status ??= BlogPostStatusEnum::Draft;

        return BlogPost::query()->create([
            ...$attributes,
            'slug' => $this->uniquePostSlug($this->slugify((string) $slugSource)),
            'status' => $status->value,
            'published_at' => $status === BlogPostStatusEnum::Published ? Carbon::now() : null,
            'created_by_id' => $author->getKey(),
        ]);
    }

    public function update(BlogPost $post, array $attributes, ?BlogPostStatusEnum $status, ?string $slugSource): BlogPost
    {
        // Only re-address the article when the author actually asked for it: a live post's
        // links are out in the world, and renaming it quietly breaks every one of them.
        if ($slugSource !== null && $this->slugify($slugSource) !== $post->slug) {
            $attributes['slug'] = $this->uniquePostSlug($this->slugify($slugSource), $post->getKey());
        }

        if ($status !== null) {
            $attributes['status'] = $status->value;

            // Stamped on the first publish only, so an article taken down and put back up
            // keeps its original date.
            if ($status === BlogPostStatusEnum::Published && $post->published_at === null) {
                $attributes['published_at'] = Carbon::now();
            }
        }

        $post->update($attributes);

        return $post->refresh();
    }

    /**
     * A slug that keeps Arabic letters — a transliterated one would be unreadable to the
     * audience this blog is written for.
     */
    public function slugify(string $input): string
    {
        $slug = mb_strtolower(trim($input));
        // Tatweel and the diacritics: decoration that must not reach the address bar.
        $slug = preg_replace('/[\x{0640}\x{064B}-\x{0652}]/u', '', $slug) ?? '';
        $slug = preg_replace('/[^a-z0-9\x{0621}-\x{064A}\x{0660}-\x{0669}]+/u', '-', $slug) ?? '';
        $slug = trim(mb_substr(trim($slug, '-'), 0, 300), '-');

        return $slug !== '' ? $slug : 'post-'.Carbon::now()->timestamp;
    }

    public function uniquePostSlug(string $base, ?int $ignoreId = null): string
    {
        return $this->unique(
            $base,
            fn (string $slug) => BlogPost::query()
                ->where('slug', $slug)
                ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
                ->exists(),
        );
    }

    /**
     * Category slugs share the same address space rule: two categories called the same
     * thing must still be reachable apart.
     */
    public function uniqueCategorySlug(string $base): string
    {
        return $this->unique(
            $base,
            fn (string $slug) => BlogCategory::query()->where('slug', $slug)->exists(),
        );
    }

    /**
     * @param  callable(string): bool  $taken
     */
    private function unique(string $base, callable $taken): string
    {
        $slug = $base;

        for ($attempt = 0; $attempt < self::SLUG_ATTEMPTS; $attempt++) {
            if (! $taken($slug)) {
                return $slug;
            }

            $slug = $base.'-'.($attempt + 2);
        }

        return $base.'-'.Carbon::now()->timestamp;
    }
}
