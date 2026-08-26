<?php

use App\Enum\Subscriptions\SubscriptionStatusEnum;
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
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_id')->constrained('subscription_plans')->restrictOnDelete();

            // Denormalised from the customer at purchase so a listing can be narrowed to
            // the caller's branches, the way every other tenant listing scopes.
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('start_at');
            $table->timestamp('end_at')->nullable();
            $table->string('status')->default(SubscriptionStatusEnum::Active->value);

            // Seeded by plan type: piece count for a quota plan, money for a prepaid
            // plan, both null for an unlimited plan.
            $table->decimal('remaining_quota', 14, 2)->nullable();
            $table->decimal('remaining_balance', 14, 2)->nullable();

            $table->boolean('auto_renew')->default(true);
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['customer_id', 'status']);
            $table->index('branch_id');
            $table->index('end_at');
        });

        $this->addEnumCheck('subscriptions', 'status', SubscriptionStatusEnum::values());
        $this->addCheck('subscriptions', 'subscriptions_quota_non_negative', '`remaining_quota` IS NULL OR `remaining_quota` >= 0');
        $this->addCheck('subscriptions', 'subscriptions_balance_non_negative', '`remaining_balance` IS NULL OR `remaining_balance` >= 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
