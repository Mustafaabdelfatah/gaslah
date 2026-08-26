<?php

use App\Enum\Platform\PlatformCouponTypeEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * Discount coupons redeemable against a tenant's platform subscription. The redemption
     * counter is bounded by max_redemptions through an atomic conditional update.
     */
    public function up(): void
    {
        Schema::create('platform_coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('type', 20);
            $table->decimal('value', 14, 2)->default(0);
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemptions')->default(0);
            $table->foreignId('applies_to_plan_id')->nullable()->constrained('platform_plans')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('note')->nullable();
            $table->timestamps();
        });

        $this->addEnumCheck('platform_coupons', 'type', PlatformCouponTypeEnum::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_coupons');
    }
};
