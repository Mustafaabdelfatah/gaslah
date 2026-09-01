<?php

namespace App\Http\Requests\Blog;

use App\Enum\Blog\BlogPostStatusEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * An article written by the operator. On update every field is optional so a partial edit
 * keeps what it did not mention — a status change must not blank the body.
 */
class BlogPostRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $required = $this->isUpdate() ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'min:3', 'max:300'],
            // The author may hand-pick the address; left out, it is derived from the title.
            'slug' => ['nullable', 'string', 'max:320'],
            'excerpt' => ['nullable', 'string', 'max:500'],
            'content' => [$required, 'string', 'min:1', 'max:40000'],
            'cover_image_url' => ['nullable', 'string', 'regex:#^(https?://|/)#', 'max:2000'],
            'category_id' => ['nullable', 'integer', Rule::exists('blog_categories', 'id')],
            'tags' => ['nullable', 'array', 'max:12'],
            'tags.*' => ['nullable', 'string', 'max:40'],
            'status' => ['nullable', Rule::in(BlogPostStatusEnum::values())],
        ];
    }

    /**
     * The written attributes, with the tags cleaned of blanks and duplicates. `slug` and
     * `status` are left to the controller: one needs uniqueness, the other decides when
     * the article goes out.
     *
     * @return array<string, mixed>
     */
    public function attributesForWrite(): array
    {
        $validated = $this->validated();
        unset($validated['slug'], $validated['status']);

        if (array_key_exists('tags', $validated)) {
            // A blank tag arrives as null: the base request nulls empty input before
            // validation, so the cleaning here has to survive that, not just trim.
            $tags = array_map(
                fn ($tag) => is_string($tag) ? trim($tag) : '',
                $validated['tags'] ?? [],
            );

            $validated['tags'] = array_values(array_unique(
                array_filter($tags, fn (string $tag) => $tag !== ''),
            ));
        }

        return $validated;
    }

    public function status(): ?BlogPostStatusEnum
    {
        return $this->filled('status')
            ? BlogPostStatusEnum::from($this->string('status')->toString())
            : null;
    }

    public function slugSource(): ?string
    {
        return $this->filled('slug')
            ? $this->string('slug')->toString()
            : ($this->filled('title') ? $this->string('title')->toString() : null);
    }

    private function isUpdate(): bool
    {
        return in_array($this->method(), ['PUT', 'PATCH'], true);
    }
}
