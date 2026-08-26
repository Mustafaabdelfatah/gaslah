<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A singleton key/value store for platform-operator settings (dunning policy,
     * aggregated settings, the reserved platform-books org id, …). String primary key,
     * JSON value, no created_at.
     */
    public function up(): void
    {
        Schema::create('platform_configs', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamp('updated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_configs');
    }
};
