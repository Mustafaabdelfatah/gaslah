<?php

use App\Enum\Loyalty\LoyaltyTransactionTypeEnum;
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
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained('loyalty_accounts')->cascadeOnDelete();
            $table->string('type');

            // Signed: positive adds, negative draws down.
            $table->decimal('points', 14, 2);

            // The related order, for a future automatic earn; not written today.
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('note', 300)->nullable();

            // When this movement's points expire; not written or read today.
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('account_id');
        });

        $this->addEnumCheck('loyalty_transactions', 'type', LoyaltyTransactionTypeEnum::values());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};
