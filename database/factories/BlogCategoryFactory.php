<?php

namespace Database\Factories;

use App\Models\BlogCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class BlogCategoryFactory extends Factory
{
    protected $model = BlogCategory::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'slug' => Str::slug($this->faker->unique()->words(3, true)),
            'is_active' => true,
        ];
    }
}
