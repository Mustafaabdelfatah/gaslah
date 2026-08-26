<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only record of each dunning action, and the guarantee that every
     * (organization, stage, period) fires at most once — the unique key IS the guard.
     */
    public function up(): void
    {
        Schema::create('dunning_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->string('stage', 40);
            $table->string('message', 40)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['organization_id', 'key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dunning_logs');
    }
};
