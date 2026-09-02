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
        Schema::table('payments', function (Blueprint $table) {
            // The payment amount is what the order actually collected. When a cashier
            // returns change, keep the note/coin amount separately so the receipt can
            // state both what was handed over and what was returned without inflating
            // sales, the drawer or accounting entries.
            $table->decimal('cash_tendered', 14, 2)->nullable()->after('amount');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('cash_tendered');
        });
    }
};
