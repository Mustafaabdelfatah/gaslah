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
        // A staff member's declared monthly salary, used to weigh what a branch or a
        // person costs against what they bring in. Payroll is not run here and no
        // journal entry is posted from this — it is a planning figure the owner
        // states, exactly like a budget line.
        Schema::create('employee_costs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('monthly_salary', 14, 2);
            $table->string('note', 255)->nullable();
            $table->timestamps();

            // One declared salary per person per organization.
            $table->unique(['organization_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('employee_costs');
    }
};
