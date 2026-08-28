<?php

use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * An operating cost of the platform itself (marketing, salaries, hosting …), feeding
     * the SaaS income statement.
     *
     * A partner may front the money personally; when they do, the expense also carries a
     * debt back to them until it is reimbursed. No updated_at — an expense is a record of
     * what was spent, not a document that gets revised.
     */
    public function up(): void
    {
        Schema::create('platform_expenses', function (Blueprint $table) {
            $table->id();
            $table->date('date');
            $table->string('category', 80);
            $table->decimal('amount', 14, 2);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('paid_by_partner_id')->nullable()->constrained('platform_partners')->nullOnDelete();
            $table->timestamp('reimbursed_at')->nullable();
            $table->foreignId('reimbursed_by_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('created_at')->nullable();

            $table->index('date');
            // Finding what the platform still owes its partners is a first-class question.
            $table->index(['paid_by_partner_id', 'reimbursed_at']);
        });

        $this->addCheck('platform_expenses', 'platform_expense_amount_positive', '`amount` > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_expenses');
    }
};
