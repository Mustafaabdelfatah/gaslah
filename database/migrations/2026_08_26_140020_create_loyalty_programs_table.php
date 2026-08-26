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
        Schema::create('loyalty_programs', function (Blueprint $table) {
            $table->id();

            // One program per organization.
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();

            $table->string('name', 200);

            // How many points are earned per unit. Defined but not used by any automatic
            // earning yet.
            $table->decimal('earn_rate', 10, 2)->default(1);

            // The currency value of one point at redemption.
            $table->decimal('point_value', 10, 4)->default(0);

            // Points lifetime in months. Defined but no expiry logic applies it.
            $table->unsignedSmallInteger('expiry_months')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        $this->addCheck('loyalty_programs', 'loyalty_programs_earn_rate_range', '`earn_rate` >= 0 AND `earn_rate` <= 10000');
        $this->addCheck('loyalty_programs', 'loyalty_programs_point_value_range', '`point_value` >= 0 AND `point_value` <= 10000');
        $this->addCheck('loyalty_programs', 'loyalty_programs_expiry_range', '`expiry_months` IS NULL OR (`expiry_months` >= 1 AND `expiry_months` <= 120)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('loyalty_programs');
    }
};
