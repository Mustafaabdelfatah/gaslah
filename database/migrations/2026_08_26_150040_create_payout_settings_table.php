<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Platform-wide payout settings — a single row.
     */
    public function up(): void
    {
        Schema::create('payout_settings', function (Blueprint $table) {
            $table->id();
            $table->decimal('fee_fixed', 14, 2)->default(0);
            $table->decimal('fee_percent', 6, 3)->default(0);
            $table->decimal('min_amount', 14, 2)->default(0);
            $table->unsignedTinyInteger('required_approvals')->default(2);
            $table->json('days')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payout_settings');
    }
};
