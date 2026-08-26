<?php

use App\Enum\Delivery\DeliverySourceEnum;
use App\Enum\Delivery\DeliveryStatusEnum;
use App\Enum\Delivery\DeliveryTypeEnum;
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
        Schema::create('delivery_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained('delivery_zones')->nullOnDelete();

            $table->string('type');
            $table->string('status')->default(DeliveryStatusEnum::Requested->value);
            $table->decimal('fee', 14, 2)->default(0);
            $table->boolean('fee_applied_to_order')->default(false);

            $table->string('address', 1000);
            $table->string('notes', 1000)->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->string('source')->default(DeliverySourceEnum::Staff->value);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('completed_at')->nullable();

            // Sub-status columns.
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('reject_reason', 500)->nullable();
            $table->timestamp('arrived_at')->nullable();
            $table->string('pickup_photo_url')->nullable();
            $table->string('delivery_photo_url')->nullable();
            $table->timestamp('inventory_done_at')->nullable();
            $table->string('inventory_notes', 1000)->nullable();
            $table->boolean('invoice_approval_required')->default(false);
            $table->timestamp('invoice_approved_at')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            // Method 3 (external delivery app).
            $table->string('external_provider', 60)->nullable();
            $table->string('external_ref', 120)->nullable();

            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['branch_id', 'type', 'status']);
            $table->index('driver_id');
            $table->index('customer_id');
            $table->index('order_id');
        });

        $this->addEnumCheck('delivery_requests', 'type', DeliveryTypeEnum::values());
        $this->addEnumCheck('delivery_requests', 'status', DeliveryStatusEnum::values());
        $this->addEnumCheck('delivery_requests', 'source', DeliverySourceEnum::values());
        $this->addCheck('delivery_requests', 'delivery_requests_fee_non_negative', '`fee` >= 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('delivery_requests');
    }
};
