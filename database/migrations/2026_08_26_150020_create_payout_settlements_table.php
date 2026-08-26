<?php

use App\Enum\Payments\SettlementStatusEnum;
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
        Schema::create('payout_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('status')->default(SettlementStatusEnum::PendingApproval->value);
            $table->boolean('urgent')->default(false);

            $table->timestamp('period_start')->nullable();
            $table->timestamp('period_end')->nullable();
            $table->unsignedInteger('payment_count')->default(0);
            $table->decimal('gross_amount', 14, 2)->default(0);
            $table->decimal('fee_amount', 14, 2)->default(0);
            $table->decimal('net_amount', 14, 2)->default(0);
            $table->string('currency', 8)->default('SAR');

            // A snapshot of the beneficiary bank at creation; masked for non-executors.
            $table->json('bank_snapshot')->nullable();

            $table->foreignId('requested_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('sent_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->string('transfer_ref')->nullable();
            $table->string('note', 500)->nullable();
            $table->string('rejected_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
        });

        $this->addEnumCheck('payout_settlements', 'status', SettlementStatusEnum::values());
        $this->addCheck('payout_settlements', 'payout_settlements_amounts', '`gross_amount` >= 0 AND `fee_amount` >= 0 AND `fee_amount` <= `gross_amount` AND `net_amount` >= 0');

        // One open settlement per organization.
        $this->addOpenSettlementIndex();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payout_settlements');
    }

    private function addOpenSettlementIndex(): void
    {
        $open = "'pending_approval', 'approved'";

        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `payout_settlements` ADD COLUMN `open_org_key` BIGINT GENERATED ALWAYS AS '
                ."(CASE WHEN `status` IN ({$open}) THEN `organization_id` ELSE NULL END) VIRTUAL, "
                .'ADD UNIQUE `payout_settlements_open_org_unique` (`open_org_key`)'
            );

            return;
        }

        DB::statement(
            "CREATE UNIQUE INDEX payout_settlements_open_org_unique ON payout_settlements (organization_id) WHERE status IN ({$open})"
        );
    }
};
