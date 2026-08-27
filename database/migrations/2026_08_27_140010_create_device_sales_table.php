<?php

use App\Enum\Platform\SubscriptionInvoiceStatusEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * A ZATCA tax invoice for hardware the platform sold.
     *
     * The buyer is either a tenant (organization_id) or a named outside party, which is why
     * the organization is nullable and the buyer's name is captured on the row. The ICV/PIH
     * chain (prefix DEV-) is separate from the subscription series: two independent
     * sequences, each unbroken. No updated_at.
     */
    public function up(): void
    {
        Schema::create('device_sales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedInteger('invoice_no')->nullable()->unique();

            $table->string('buyer_name');
            $table->string('buyer_vat')->nullable();
            $table->string('seller_name')->nullable();
            $table->string('seller_vat')->nullable();

            // A snapshot of the lines as sold: the catalogue may be re-priced later, but a
            // tax invoice must keep reporting what was actually charged.
            $table->json('items');
            $table->string('notes', 1000)->nullable();

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

        $this->addEnumCheck('device_sales', 'status', SubscriptionInvoiceStatusEnum::values());
        $this->addCheck('device_sales', 'device_sale_totals_valid', '`subtotal` >= 0 AND `vat` >= 0 AND `total` >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('device_sales');
    }
};
