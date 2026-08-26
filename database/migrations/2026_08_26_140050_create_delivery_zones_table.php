<?php

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
        Schema::create('delivery_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            $table->string('name', 200);
            $table->string('name_en', 200)->nullable();

            // The zone fee overrides self-delivery pricing when a request picks the zone.
            $table->decimal('fee', 14, 2)->default(0);

            // Reference data for the zone; not written by the staff UI today.
            $table->json('postal_codes')->nullable();
            $table->unsignedSmallInteger('eta_minutes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['branch_id', 'is_active']);
        });

        $this->addCheck('delivery_zones', 'delivery_zones_fee_non_negative', '`fee` >= 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_zones');
    }
};
