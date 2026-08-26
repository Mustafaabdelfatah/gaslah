<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Unit of measure for inventory. Managed by the inventory module; referenced
        // here only so catalog/inventory can share it.
        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('symbol')->nullable();
            $table->decimal('conversion_factor', 12, 4)->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
