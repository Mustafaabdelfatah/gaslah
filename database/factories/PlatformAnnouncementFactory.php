<?php

namespace Database\Factories;

use App\Enum\Platform\PlatformAnnouncementLevelEnum;
use App\Models\PlatformAnnouncement;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlatformAnnouncementFactory extends Factory
{
    protected $model = PlatformAnnouncement::class;

    public function definition(): array
    {
        return [
            'title' => $this->faker->sentence(3),
            'body' => $this->faker->paragraph(),
            'level' => PlatformAnnouncementLevelEnum::Info->value,
            'organization_id' => null,
            'is_active' => true,
            'starts_at' => null,
            'ends_at' => null,
        ];
    }
}
