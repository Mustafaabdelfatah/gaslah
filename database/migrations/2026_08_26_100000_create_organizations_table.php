<?php

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
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();
            // Links a migrated row to its cuid in the old system; null for records
            // created natively.
            $table->string('legacy_cuid')->nullable()->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('custom_domain')->nullable()->unique();

            // Business profile
            $table->string('default_currency', 3)->default('SAR');
            $table->decimal('tax_rate', 5, 2)->default(15.00);
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('cr_number')->nullable();
            $table->string('vat_number')->nullable();

            // Receipt and branding
            $table->text('receipt_footer')->nullable();
            $table->unsignedSmallInteger('receipt_width')->default(80);
            $table->string('brand_primary')->nullable();
            $table->string('brand_accent')->nullable();
            $table->string('logo_url')->nullable();
            $table->json('settings')->nullable();

            // Platform controls
            $table->boolean('is_suspended')->default(false);
            $table->json('feature_overrides')->nullable();
            $table->unsignedInteger('max_branches_override')->nullable();
            $table->unsignedInteger('max_users_override')->nullable();
            $table->boolean('admin_follow_up')->default(false);
            $table->json('admin_tags')->nullable();
            $table->decimal('account_credit', 14, 2)->default(0);
            $table->json('payout_config')->nullable();

            $table->timestamps();
            $table->timestamp('archived_at')->nullable();

            $table->index('is_suspended');
            $table->index('archived_at');
        });

        $this->addCheck('organizations', 'organizations_account_credit_check', '`account_credit` >= 0');
        $this->addCheck('organizations', 'organizations_tax_rate_check', '`tax_rate` >= 0 AND `tax_rate` <= 100');
        $this->addCheck('organizations', 'organizations_receipt_width_check', '`receipt_width` > 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};
