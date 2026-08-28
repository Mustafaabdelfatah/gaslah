<?php

use App\Enum\Market\MarketCommissionTypeEnum;
use App\Enum\Market\MarketOrderStatusEnum;
use App\Enum\Market\MarketPaymentMethodEnum;
use App\Enum\Market\MarketPaymentStatusEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * A laundry's purchase from one market supplier.
     *
     * One supplier per order by design — a basket spanning two suppliers is two orders,
     * because each is confirmed, shipped and paid out separately.
     *
     * The commission columns are a snapshot taken at creation: renegotiating a supplier's
     * rate later must not rewrite what the platform already earned, nor what a supplier
     * was already told they would be paid.
     */
    public function up(): void
    {
        Schema::create('market_orders', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();

            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('supplier_id')->constrained('market_suppliers')->restrictOnDelete();

            $table->string('status', 20)->default(MarketOrderStatusEnum::Pending->value);
            $table->string('payment_method', 20)->default(MarketPaymentMethodEnum::Deferred->value);
            $table->string('payment_status', 20)->default(MarketPaymentStatusEnum::Unpaid->value);

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->string('commission_type', 20)->default(MarketCommissionTypeEnum::Percent->value);
            $table->decimal('commission_rate', 8, 2)->default(0);
            $table->decimal('commission_amount', 14, 2)->default(0);
            // What the buyer pays: the subtotal. Commission comes out of the supplier's side.
            $table->decimal('total', 14, 2)->default(0);
            $table->decimal('supplier_payout', 14, 2)->default(0);

            $table->string('address', 500)->nullable();
            $table->string('notes', 500)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['supplier_id', 'status']);
        });

        $this->addEnumCheck('market_orders', 'status', MarketOrderStatusEnum::values());
        $this->addEnumCheck('market_orders', 'payment_method', MarketPaymentMethodEnum::values());
        $this->addEnumCheck('market_orders', 'payment_status', MarketPaymentStatusEnum::values());
        $this->addEnumCheck('market_orders', 'commission_type', MarketCommissionTypeEnum::values());
        $this->addCheck(
            'market_orders',
            'market_order_money_valid',
            '`subtotal` >= 0 AND `commission_amount` >= 0 AND `commission_amount` <= `subtotal` AND `supplier_payout` >= 0',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('market_orders');
    }
};
