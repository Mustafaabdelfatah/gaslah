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
        // One debit-or-credit line of an entry. After zero lines are dropped, exactly
        // one of debit/credit is positive on each row.
        Schema::create('journal_lines', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();

            // Copied from the entry to keep isolation-scoped balance queries on a
            // single table.
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('journal_entry_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->restrictOnDelete();
            $table->decimal('debit', 14, 2)->default(0);
            $table->decimal('credit', 14, 2)->default(0);
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('memo', 255)->nullable();
            $table->timestamps();

            $table->index('account_id');
            $table->index(['organization_id', 'account_id']);
            $table->index('journal_entry_id');
        });

        $this->addCheck('journal_lines', 'journal_lines_amounts_non_negative', '`debit` >= 0 AND `credit` >= 0');
        $this->addCheck('journal_lines', 'journal_lines_one_side', '`debit` = 0 OR `credit` = 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_lines');
    }
};
