<?php

use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    public function up(): void
    {
        Schema::create('platform_plans', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('name_en', 120)->nullable();
            $table->decimal('monthly_price', 14, 2)->default(0);
            $table->decimal('yearly_price', 14, 2)->default(0);
            $table->unsignedInteger('max_branches')->default(1);
            $table->unsignedInteger('max_users')->default(3);
            $table->json('features')->nullable();       // marketing display strings
            $table->json('feature_keys')->nullable();    // entitlement keys the plan unlocks
            $table->boolean('is_popular')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->addCheck('platform_plans', 'platform_plans_prices', '`monthly_price` >= 0 AND `yearly_price` >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_plans');
    }
};
