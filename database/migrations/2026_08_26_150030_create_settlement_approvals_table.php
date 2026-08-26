<?php

use App\Enum\Payments\SettlementDecisionEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * Run the migrations.
     *
     * Append-only admin votes. One vote per admin per settlement (unique index); a double
     * vote collides.
     */
    public function up(): void
    {
        Schema::create('settlement_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('settlement_id')->constrained('payout_settlements')->cascadeOnDelete();
            $table->foreignId('admin_id')->constrained('users')->restrictOnDelete();
            $table->string('decision');
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['settlement_id', 'admin_id']);
        });

        $this->addEnumCheck('settlement_approvals', 'decision', SettlementDecisionEnum::values());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settlement_approvals');
    }
};
