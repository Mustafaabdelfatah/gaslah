<?php

use App\Enum\Market\MarketCommissionTypeEnum;
use App\Enum\Market\MarketSupplierStatusEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * A business selling supplies to laundries through the platform's market.
     *
     * Platform-level, not tenant-owned: one supplier sells to many laundries, which is why
     * this carries no organization_id. Distinct from the tenant's own `suppliers` table,
     * which records who an individual laundry buys from directly.
     */
    public function up(): void
    {
        Schema::create('market_suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();

            $table->string('name');
            // Lower-cased on write, so sign-in matches however the supplier types it.
            $table->string('email')->unique();
            $table->string('phone', 32)->nullable();
            $table->string('password');

            $table->string('status', 20)->default(MarketSupplierStatusEnum::Pending->value);
            $table->string('description', 1000)->nullable();
            $table->string('city', 120)->nullable();
            $table->string('logo_url', 500)->nullable();

            $table->string('commission_type', 20)->default(MarketCommissionTypeEnum::Percent->value);
            $table->decimal('commission_value', 8, 2)->nullable();

            $table->timestamp('approved_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        $this->addEnumCheck('market_suppliers', 'status', MarketSupplierStatusEnum::values());
        $this->addEnumCheck('market_suppliers', 'commission_type', MarketCommissionTypeEnum::values());
        $this->addCheck('market_suppliers', 'market_commission_not_negative', '`commission_value` IS NULL OR `commission_value` >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('market_suppliers');
    }
};
