<?php

use App\Enum\Zatca\ZatcaInvoiceStatusEnum;
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
     * One ZATCA invoice per order. ICV is a per-organization sequential counter, and the
     * hash chains each invoice to the previous one (PIH).
     */
    public function up(): void
    {
        Schema::create('zatca_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('icv');
            $table->uuid('uuid');
            $table->text('pih');
            $table->text('hash');
            $table->longText('xml');
            $table->text('qr');
            $table->string('status')->default(ZatcaInvoiceStatusEnum::Generated->value);
            $table->uuid('zatca_uuid')->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->timestamps();

            // ICV is unique within an organization.
            $table->unique(['organization_id', 'icv']);
        });

        $this->addEnumCheck('zatca_invoices', 'status', ZatcaInvoiceStatusEnum::values());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zatca_invoices');
    }
};
