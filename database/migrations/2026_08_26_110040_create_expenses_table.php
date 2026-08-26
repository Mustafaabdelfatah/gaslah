<?php

use App\Enum\Accounting\ExpenseCategoryEnum;
use App\Enum\Accounting\ExpensePaidFromEnum;
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
        // A posted expense. paid_from = AP marks a supplier bill (the payable row and
        // supplier link arrive with the operations module); CASH/BANK are settled now.
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->string('category');
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 14, 2);
            $table->decimal('vat_amount', 14, 2)->default(0);
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->string('paid_from')->default('cash');
            $table->string('reference', 100)->nullable();
            $table->foreignId('journal_entry_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'date']);
            $table->index(['organization_id', 'category']);
            $table->index('branch_id');
            $table->index('account_id');
            $table->index('journal_entry_id');
        });

        $this->addEnumCheck('expenses', 'category', ExpenseCategoryEnum::values());
        $this->addEnumCheck('expenses', 'paid_from', ExpensePaidFromEnum::values());
        $this->addCheck('expenses', 'expenses_amounts_valid', '`amount` > 0 AND `vat_amount` >= 0 AND `vat_amount` <= `amount`');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
