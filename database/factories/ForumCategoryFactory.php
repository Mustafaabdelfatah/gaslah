<?php

namespace Database\Factories;

use App\Models\ForumCategory;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ForumCategoryFactory extends Factory
{
    protected $model = ForumCategory::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'slug' => Str::slug($this->faker->unique()->words(3, true)),
            'is_active' => true,
        ];
    }
}
