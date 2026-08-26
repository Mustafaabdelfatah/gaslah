<?php

use App\Enum\Payments\PaymentMethodEnum;
use App\Enum\Payments\PaymentVerifyModeEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // One collected payment on an order. Deferred and subscription payments never
        // create a row here.
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('method');
            $table->decimal('amount', 14, 2);

            // For a gateway payment this is "gateway:{txnId}", the idempotency key.
            $table->string('reference')->nullable();
            $table->string('verify_mode')->nullable();
            $table->foreignId('shift_id')->nullable();

            // true when collected on the platform's gateway account, so it joins the
            // settlement pool rather than the branch till.
            $table->boolean('via_gateway')->default(false);
            $table->foreignId('settlement_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('order_id');
            $table->index('shift_id');
            $table->index('settlement_id');
            $table->index(['via_gateway', 'settlement_id']);
        });

        // Gateway reference is unique to stop a provider transaction settling twice.
        $this->addPaymentReferenceIndex();

        $this->addEnumCheck('payments', 'method', PaymentMethodEnum::values());
        $this->addEnumCheck('payments', 'verify_mode', PaymentVerifyModeEnum::values(), nullable: true);
        $this->addCheck('payments', 'payments_amount_positive', '`amount` > 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payments');
    }

    private function addPaymentReferenceIndex(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `payments` ADD COLUMN `reference_uniq` VARCHAR(255) GENERATED ALWAYS AS '
                .'(CASE WHEN `reference` IS NULL THEN NULL ELSE `reference` END) VIRTUAL, '
                .'ADD UNIQUE `payments_reference_unique` (`reference_uniq`)'
            );

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX payments_reference_unique ON payments (reference) WHERE reference IS NOT NULL'
        );
    }
};
