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
        // A planned spend for one expense category in one month. Budgets post no
        // journal entries at all — they exist to be compared against the expenses
        // that were actually posted.
        Schema::create('budgets', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            // Null means the line covers the whole organization rather than one branch.
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('category');
            // The month being planned, as YYYY-MM.
            $table->string('month', 7);
            $table->decimal('amount', 14, 2);
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // One line per scope: re-planning the same category and month updates the
            // existing row instead of stacking a second budget beside it.
            $table->unique(['organization_id', 'branch_id', 'category', 'month'], 'budgets_scope_unique');
            $table->index(['organization_id', 'month']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('budgets');
    }
};
