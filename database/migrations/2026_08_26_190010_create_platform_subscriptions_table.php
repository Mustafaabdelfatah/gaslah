<?php

use App\Enum\Platform\PlatformCycleEnum;
use App\Enum\Platform\PlatformSubscriptionStatusEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * One platform subscription per organization.
     */
    public function up(): void
    {
        Schema::create('platform_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('platform_plans')->restrictOnDelete();
            $table->string('cycle')->default(PlatformCycleEnum::Monthly->value);
            $table->string('status')->default(PlatformSubscriptionStatusEnum::Trial->value);
            $table->decimal('price', 14, 2)->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);
            $table->timestamp('canceled_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        $this->addEnumCheck('platform_subscriptions', 'cycle', PlatformCycleEnum::values());
        $this->addEnumCheck('platform_subscriptions', 'status', PlatformSubscriptionStatusEnum::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_subscriptions');
    }
};
