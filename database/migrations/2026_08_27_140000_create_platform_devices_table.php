<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hardware the platform sells to tenants (a POS terminal, a receipt printer …).
     * The price is VAT-inclusive, matching how plans are priced.
     */
    public function up(): void
    {
        Schema::create('platform_devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('sku', 80)->nullable()->unique();
            $table->decimal('price', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_devices');
    }
};
