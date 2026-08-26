<?php

use App\Enum\Affiliate\AffiliateReferralStatusEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    public function up(): void
    {
        Schema::create('affiliate_referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('affiliate_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('plan_name', 120)->nullable();
            $table->decimal('sub_amount', 14, 2)->default(0);
            $table->decimal('commission', 14, 2)->default(0);
            $table->string('status')->default(AffiliateReferralStatusEnum::Pending->value);
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['affiliate_id', 'status']);
        });

        $this->addEnumCheck('affiliate_referrals', 'status', AffiliateReferralStatusEnum::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_referrals');
    }
};
