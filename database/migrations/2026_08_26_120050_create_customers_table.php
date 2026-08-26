<?php

use App\Enum\Catalog\CustomerTypeEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();

            // 2–200 chars: the name is copied into the ZATCA invoice XML and WhatsApp
            // templates, so it must be long enough to hold a real business name.
            $table->string('name', 200);
            $table->string('phone', 32);
            $table->string('email')->nullable();
            $table->string('address', 500)->nullable();
            $table->date('birth_date')->nullable();
            $table->string('type')->default('regular');
            $table->decimal('credit_limit', 14, 2)->nullable();

            // The stored wallet balance. Never mass-assignable and only ever written
            // by the wallet service under a row lock.
            $table->decimal('wallet_balance', 14, 2)->default(0);
            $table->json('preferences')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['organization_id', 'type']);
            $table->index('branch_id');
        });

        // Phone is unique within an organization, not globally: two tenants may serve
        // the same person.
        $this->addCustomerPhoneUniqueIndex();

        $this->addEnumCheck('customers', 'type', CustomerTypeEnum::values());
        $this->addCheck('customers', 'customers_credit_limit_non_negative', '`credit_limit` IS NULL OR `credit_limit` >= 0');
        $this->addCheck('customers', 'customers_wallet_non_negative', '`wallet_balance` >= 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customers');
    }

    private function addCustomerPhoneUniqueIndex(): void
    {
        // Soft-deleted rows keep their phone; the guard applies to live rows only.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `customers` ADD COLUMN `phone_uniq` VARCHAR(300) GENERATED ALWAYS AS '
                .'(CASE WHEN `deleted_at` IS NULL THEN CONCAT(`organization_id`, ":", `phone`) ELSE NULL END) VIRTUAL, '
                .'ADD UNIQUE `customers_org_phone_unique` (`phone_uniq`)'
            );

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX customers_org_phone_unique ON customers (organization_id, phone) WHERE deleted_at IS NULL'
        );
    }
};
