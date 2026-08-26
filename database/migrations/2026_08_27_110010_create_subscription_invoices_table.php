<?php

use App\Enum\Platform\PlatformCycleEnum;
use App\Enum\Platform\SubscriptionInvoiceStatusEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * A ZATCA tax invoice the SaaS operator issues against a tenant subscription.
     *
     * The seller is the platform, so the ICV/PIH chain (prefix SUB-) is platform-wide
     * rather than per-tenant. A draft carries no chain slot (icv/invoice_no null) and is
     * freely deletable; confirming assigns the next slot and freezes the row. No updated_at.
     */
    public function up(): void
    {
        Schema::create('subscription_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained('platform_subscriptions')->nullOnDelete();
            $table->foreignId('charge_id')->nullable()->unique();

            $table->unsignedInteger('invoice_no')->nullable()->unique();

            $table->string('seller_name')->nullable();
            $table->string('seller_vat')->nullable();
            $table->string('buyer_name')->nullable();
            $table->string('buyer_vat')->nullable();

            $table->string('plan_name')->nullable();
            $table->string('cycle', 20)->default(PlatformCycleEnum::Monthly->value);

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('vat', 14, 2)->default(0);
            $table->decimal('total', 14, 2)->default(0);

            $table->string('payment_method', 20)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('transfer_ref')->nullable();
            $table->string('gateway_name')->nullable();

            $table->unsignedInteger('icv')->nullable()->unique();
            $table->text('pih')->nullable();
            $table->text('hash')->nullable();
            $table->text('qr')->nullable();

            $table->string('status', 20)->default(SubscriptionInvoiceStatusEnum::Draft->value);

            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('confirmed_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['organization_id', 'status']);
        });

        $this->addEnumCheck('subscription_invoices', 'status', SubscriptionInvoiceStatusEnum::values());
        $this->addEnumCheck('subscription_invoices', 'cycle', PlatformCycleEnum::values());
        $this->addCheck('subscription_invoices', 'sub_invoice_totals_valid', '`subtotal` >= 0 AND `vat` >= 0 AND `total` >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_invoices');
    }
};
