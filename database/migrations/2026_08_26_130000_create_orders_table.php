<?php

use App\Enum\Orders\OrderPriorityEnum;
use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Orders\PaymentStatusEnum;
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
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            // The branch the order was created at, pinned from the token and never
            // moved by the read-scope header.
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('cashier_id')->nullable()->constrained('users')->nullOnDelete();

            // Set only when paid from a subscription — the trace that lets a cancel
            // restore the consumed quota or balance.
            $table->foreignId('subscription_id')->nullable();

            $table->string('order_no', 40);
            $table->string('barcode', 40);
            $table->string('status')->default('received');
            $table->string('priority')->default('normal');
            $table->string('payment_status')->default('unpaid');
            $table->timestamp('due_at')->nullable();
            $table->string('notes', 1000)->nullable();

            // Totals, all a server-side snapshot; the client's prices are never trusted.
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('tax_total', 14, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(15.00);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->decimal('paid_total', 14, 2)->default(0);
            $table->decimal('delivery_fee', 14, 2)->default(0);

            // Idempotency key for an offline-synced cart.
            $table->string('client_request_id', 80)->nullable();

            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'order_no']);
            $table->unique('barcode');
            $table->index(['organization_id', 'status']);
            $table->index(['branch_id', 'created_at']);
            $table->index(['branch_id', 'status', 'archived_at']);
            $table->index(['branch_id', 'delivered_at']);
            $table->index(['organization_id', 'payment_status']);
            $table->index('customer_id');
            $table->index('subscription_id');
        });

        // Second idempotency barrier for a concurrent offline re-sync of the same cart.
        $this->addClientRequestIndex();

        $this->addEnumCheck('orders', 'status', OrderStatusEnum::values());
        $this->addEnumCheck('orders', 'priority', OrderPriorityEnum::values());
        $this->addEnumCheck('orders', 'payment_status', PaymentStatusEnum::values());
        $this->addCheck('orders', 'orders_totals_valid', '`subtotal` >= 0 AND `discount_total` >= 0 AND `discount_total` <= `subtotal` AND `tax_total` >= 0 AND `grand_total` >= 0 AND `paid_total` >= 0');
        $this->addCheck('orders', 'orders_tax_rate_valid', '`tax_rate` >= 0 AND `tax_rate` <= 100');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('orders');
    }

    private function addClientRequestIndex(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `orders` ADD COLUMN `client_request_uniq` VARCHAR(160) GENERATED ALWAYS AS '
                .'(CASE WHEN `client_request_id` IS NULL THEN NULL ELSE CONCAT(`branch_id`, ":", `client_request_id`) END) VIRTUAL, '
                .'ADD UNIQUE `orders_branch_client_request_unique` (`client_request_uniq`)'
            );

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX orders_branch_client_request_unique ON orders (branch_id, client_request_id) WHERE client_request_id IS NOT NULL'
        );
    }
};
