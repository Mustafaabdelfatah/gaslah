<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One delivery configuration per organization. `settings` holds the organization's
     * own choices (merged over defaults at read time); `available_methods` is the
     * platform-controlled availability of the three delivery methods.
     */
    public function up(): void
    {
        Schema::create('delivery_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('settings')->nullable();
            $table->json('available_methods')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_settings');
    }
};
