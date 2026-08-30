<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CountrySeeder::class,
            SettingTableSeeder::class,
            FeatureSeeder::class,
            UserTableSeeder::class,
            // A ready-to-click Gaslah demo tenant (idempotent). Seed just the base
            // without it via: php artisan db:seed --class=UserTableSeeder
            DemoSeeder::class,
        ]);
    }
}
