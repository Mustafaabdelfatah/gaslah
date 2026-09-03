<?php

use App\Enum\Accounting\ExpenseCategoryEnum;
use App\Enum\Accounting\ExpensePaidFromEnum;
use App\Enum\Accounting\PayableSettlementMethodEnum;
use App\Enum\Accounting\PayableStatusEnum;
use App\Enum\Accounting\RecurringFrequencyEnum;
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
        // A reusable schedule that materializes either an AP bill or a directly-paid
        // expense. Keeping it relational makes due sweeps and tenant isolation explicit.
        Schema::create('recurring_bills', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('category');
            $table->decimal('amount', 14, 2);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('paid_from');
            $table->string('frequency');
            $table->unsignedSmallInteger('anchor_day')->default(1);
            $table->unsignedSmallInteger('due_days')->default(0);
            $table->date('next_run');
            $table->date('last_run')->nullable();
            $table->unsignedInteger('generated_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'is_active', 'next_run']);
            $table->index('supplier_id');
            $table->index('branch_id');
        });

        $this->addEnumCheck('recurring_bills', 'category', ExpenseCategoryEnum::values());
        $this->addEnumCheck('recurring_bills', 'paid_from', ExpensePaidFromEnum::values());
        $this->addEnumCheck('recurring_bills', 'frequency', RecurringFrequencyEnum::values());
        $this->addCheck('recurring_bills', 'recurring_bills_anchor_day_check', '`anchor_day` BETWEEN 1 AND 31');
        $this->addCheck('recurring_bills', 'recurring_bills_due_days_check', '`due_days` BETWEEN 0 AND 180');
        $this->addCheck('recurring_bills', 'recurring_bills_amounts_valid', '`amount` > 0 AND `vat_amount` >= 0 AND `vat_amount` <= `amount`');

        // One payable row enriches one AP-funded expense with its supplier, due date and
        // settlement state. The ledger remains authoritative for the financial effect.
        Schema::create('payables', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('expense_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('supplier_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('bill_no', 100)->nullable();
            $table->date('issue_date');
            $table->date('due_date');
            $table->string('status')->default(PayableStatusEnum::Open->value);
            $table->timestamp('paid_at')->nullable();
            $table->string('paid_via')->nullable();
            $table->foreignId('paid_journal_entry_id')->nullable()->constrained('journal_entries')->restrictOnDelete();
            $table->foreignId('recurring_bill_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'due_date']);
            $table->index('supplier_id');
            $table->index('recurring_bill_id');
        });

        $this->addEnumCheck('payables', 'status', PayableStatusEnum::values());
        $this->addEnumCheck('payables', 'paid_via', PayableSettlementMethodEnum::values(), nullable: true);
        $this->addCheck(
            'payables',
            'payables_paid_state_valid',
            "`status` <> 'paid' OR (`paid_at` IS NOT NULL AND `paid_journal_entry_id` IS NOT NULL)",
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payables');
        Schema::dropIfExists('recurring_bills');
    }
};
