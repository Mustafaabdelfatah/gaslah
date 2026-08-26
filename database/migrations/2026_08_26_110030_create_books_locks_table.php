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
        // One period-lock row per organization. Everything dated on or before
        // closed_through is frozen against user-dated postings.
        Schema::create('books_locks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->date('closed_through')->nullable();
            $table->foreignId('updated_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('books_locks');
    }
};
