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
        ]);

        // The Gaslah demo tenant is seeded on its own (php artisan db:seed
        // --class=DemoSeeder) so it stays decoupled from the base-kit user seeder.
    }

    /**
     * Seed the base reference data plus a ready-to-use Gaslah demo tenant.
     */
    public function withDemo(): void
    {
        $this->call([
            CountrySeeder::class,
            SettingTableSeeder::class,
            FeatureSeeder::class,
            DemoSeeder::class,
        ]);
    }
}
