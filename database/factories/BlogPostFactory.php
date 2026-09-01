<?php

namespace Database\Factories;

use App\Enum\Blog\BlogPostStatusEnum;
use App\Models\BlogPost;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BlogPostFactory extends Factory
{
    protected $model = BlogPost::class;

    public function definition(): array
    {
        $title = $this->faker->unique()->sentence(4);

        return [
            'title' => $title,
            'slug' => Str::slug($title),
            'excerpt' => $this->faker->sentence(),
            'content' => $this->faker->paragraphs(3, true),
            'tags' => [],
            'status' => BlogPostStatusEnum::Draft->value,
        ];
    }

    /**
     * Live and already out — what a reader can actually reach.
     */
    public function published(): static
    {
        return $this->state(fn () => [
            'status' => BlogPostStatusEnum::Published->value,
            'published_at' => now()->subDay(),
        ]);
    }

    /**
     * Published, but dated forward: written, not out yet.
     */
    public function scheduled(): static
    {
        return $this->state(fn () => [
            'status' => BlogPostStatusEnum::Published->value,
            'published_at' => now()->addWeek(),
        ]);
    }
}
