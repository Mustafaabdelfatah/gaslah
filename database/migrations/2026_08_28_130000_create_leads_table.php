<?php

use App\Enum\Crm\LeadStageEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * A prospective laundry in the operator's sales pipeline.
     *
     * `converted_organization_id` is what makes conversion idempotent: once a lead has
     * become a real tenant, the column is set and a second conversion is refused rather
     * than provisioning a duplicate organization.
     */
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();

            $table->string('business_name', 200);
            $table->string('contact_name', 200)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 255)->nullable();
            $table->string('city', 100)->nullable();
            $table->string('source', 60)->nullable();

            $table->string('stage', 20)->default(LeadStageEnum::New->value);

            // What this account would be worth per month if it closed. Drives the
            // pipeline value, so it is money, not a guess field.
            $table->decimal('expected_mrr', 12, 2)->nullable();

            // The salesperson who owns it. Survives them leaving.
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();

            // Set once, on conversion. Its presence is what refuses a second one.
            $table->foreignId('converted_organization_id')->nullable()
                ->constrained('organizations')->nullOnDelete();

            $table->string('lost_reason', 500)->nullable();
            $table->timestamp('won_at')->nullable();

            $table->timestamps();

            // The board: one column per stage, newest first.
            $table->index(['stage', 'created_at']);
            $table->index('owner_id');
        });

        $this->addEnumCheck('leads', 'stage', LeadStageEnum::values());
        $this->addCheck('leads', 'lead_expected_mrr_valid', '`expected_mrr` IS NULL OR `expected_mrr` >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
