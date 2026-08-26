<?php

use App\Enum\Payments\OnlineChargePurposeEnum;
use App\Enum\Payments\OnlineChargeStatusEnum;
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
     * The gateway charge ledger — the mirror of every provider transaction. It exists
     * beside Payment because the platform payments monitor reads it exclusively, and
     * because Payment.method loses the provider, currency, and provider reference.
     */
    public function up(): void
    {
        Schema::create('online_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('provider', 40);
            $table->string('provider_ref')->nullable();
            $table->string('purpose')->default(OnlineChargePurposeEnum::OrderPayment->value);
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_id')->nullable();
            $table->decimal('amount', 14, 2);
            $table->string('currency', 8)->default('SAR');
            $table->string('status')->default(OnlineChargeStatusEnum::Initiated->value);

            // Reuses Payment.reference ("gateway:{txnId}") — unique per provider
            // transaction and the key the webhook de-duplicates on.
            $table->string('idempotency_key')->unique();
            $table->string('raw_status')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('order_id');
            $table->index('provider_ref');
        });

        $this->addEnumCheck('online_charges', 'status', OnlineChargeStatusEnum::values());
        $this->addEnumCheck('online_charges', 'purpose', OnlineChargePurposeEnum::values());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('online_charges');
    }
};
