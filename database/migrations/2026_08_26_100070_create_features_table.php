<?php

use App\Enum\Tenancy\FeatureCategoryEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The single catalogue driving the admin feature switches, the plan feature
        // keys, and the requireFeature gate. Adding a row here is all it takes to
        // make a new capability gateable.
        Schema::create('features', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('name');
            $table->string('category');

            // Core features are never gated: they remain enabled even when the
            // subscription lapses, and an override cannot switch them off.
            $table->boolean('is_core')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index('category');
            $table->index('is_core');
        });

        $this->addEnumCheck('features', 'category', FeatureCategoryEnum::values());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('features');
    }
};
