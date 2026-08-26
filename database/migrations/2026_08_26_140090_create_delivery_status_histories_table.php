<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * An audit row per delivery status change. It carries a single `at` timestamp
     * rather than created/updated pair.
     */
    public function up(): void
    {
        Schema::create('delivery_status_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('request_id')->constrained('delivery_requests')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('note', 500)->nullable();
            $table->timestamp('at')->useCurrent();

            $table->index('request_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_status_histories');
    }
};
