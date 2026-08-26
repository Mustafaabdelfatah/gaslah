<?php

use App\Enum\Subscriptions\SubscriptionCycleEnum;
use App\Enum\Subscriptions\SubscriptionTypeEnum;
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
        Schema::create('subscription_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            $table->string('name', 200);
            $table->string('cycle')->default(SubscriptionCycleEnum::Monthly->value);
            $table->string('type')->default(SubscriptionTypeEnum::PieceQuota->value);
            $table->decimal('price', 14, 2)->default(0);

            // The reference value used to seed a subscription's balance. Interpreted by
            // type: piece count, prepaid money, or ignored for unlimited.
            $table->decimal('quota', 14, 2)->nullable();

            // Present in the model but not read by any purchase/consume logic yet.
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();

            $table->boolean('auto_renew')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'is_active']);
        });

        $this->addEnumCheck('subscription_plans', 'cycle', SubscriptionCycleEnum::values());
        $this->addEnumCheck('subscription_plans', 'type', SubscriptionTypeEnum::values());
        $this->addCheck('subscription_plans', 'subscription_plans_price_non_negative', '`price` >= 0');
        $this->addCheck('subscription_plans', 'subscription_plans_quota_non_negative', '`quota` IS NULL OR `quota` >= 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscription_plans');
    }
};
