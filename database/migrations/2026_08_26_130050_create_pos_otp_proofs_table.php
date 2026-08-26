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
        // A one-shot proof that a present customer approved a wallet debit.
        //
        // It is burned atomically before any money moves: the deletion that finds the
        // row still present is the one that "wins" and may proceed, so two concurrent
        // payments carrying the same proof can never both succeed (no double debit).
        Schema::create('pos_otp_proofs', function (Blueprint $table) {
            $table->id();
            $table->string('token_hash', 64)->unique();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->nullable();

            $table->index('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pos_otp_proofs');
    }
};
