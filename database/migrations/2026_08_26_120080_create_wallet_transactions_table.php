<?php

use App\Enum\Payments\WalletTransactionTypeEnum;
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
        // One ledger row per wallet movement. balance_after records the running
        // balance under the same lock, so it never drifts from customers.wallet_balance.
        Schema::create('wallet_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->decimal('amount', 14, 2);
            $table->decimal('balance_after', 14, 2);

            // A loose polymorphic reference (order / loyalty / manual) with no strict
            // FK, matching how different flows attribute a movement.
            $table->unsignedBigInteger('ref_id')->nullable();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['customer_id', 'created_at']);
            $table->index('ref_id');
        });

        $this->addEnumCheck('wallet_transactions', 'type', WalletTransactionTypeEnum::values());
        $this->addCheck('wallet_transactions', 'wallet_transactions_amount_positive', '`amount` > 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('wallet_transactions');
    }
};
