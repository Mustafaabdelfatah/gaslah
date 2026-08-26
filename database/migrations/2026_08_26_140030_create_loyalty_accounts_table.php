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
        Schema::create('loyalty_accounts', function (Blueprint $table) {
            $table->id();

            // One account per customer.
            $table->foreignId('customer_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('program_id')->constrained('loyalty_programs')->restrictOnDelete();

            // A tier reference for a future tiers feature; present but unused.
            $table->unsignedBigInteger('tier_id')->nullable();

            // The redeemable balance; never drops below zero.
            $table->decimal('points_balance', 14, 2)->default(0);

            // Lifetime earned; only ever increases.
            $table->decimal('lifetime_points', 14, 2)->default(0);
            $table->timestamps();
        });

        $this->addCheck('loyalty_accounts', 'loyalty_accounts_balance_non_negative', '`points_balance` >= 0');
        $this->addCheck('loyalty_accounts', 'loyalty_accounts_lifetime_non_negative', '`lifetime_points` >= 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_accounts');
    }
};
