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
        // Per-organization deferred-payment (credit) configuration. One row per org.
        Schema::create('organization_credit_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->restrictOnDelete();
            $table->boolean('is_enabled')->default(false);
            $table->decimal('default_limit', 14, 2)->default(0);
            $table->timestamps();
        });

        $this->addCheck('organization_credit_settings', 'credit_default_limit_valid', '`default_limit` >= 0 AND `default_limit` <= 10000000');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_credit_settings');
    }
};
