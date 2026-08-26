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
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();

            // Nullable: filled for a platform driver with the owning platform org.
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();

            // A driver is always homed to a branch, platform drivers included.
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();

            $table->string('name', 200);

            // Unique system-wide: a phone resolves a single driver at OTP login, so it
            // must never point at two.
            $table->string('phone', 32)->unique();

            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('vehicle', 200)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();

            // A shared platform driver vs an organization's own driver.
            $table->boolean('is_platform')->default(false);
            $table->string('coverage_region', 200)->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'is_active']);
            $table->index(['is_platform', 'is_active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('drivers');
    }
};
