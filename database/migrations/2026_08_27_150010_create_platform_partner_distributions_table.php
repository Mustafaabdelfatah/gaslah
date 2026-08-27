<?php

use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * Cash paid out to a partner against their share. No updated_at: a distribution is a
     * record of money that left, not a document that gets revised.
     */
    public function up(): void
    {
        Schema::create('platform_partner_distributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('partner_id')->constrained('platform_partners')->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->date('date');
            $table->string('note', 500)->nullable();
            $table->foreignId('recorded_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['partner_id', 'date']);
        });

        $this->addCheck('platform_partner_distributions', 'distribution_amount_positive', '`amount` > 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_partner_distributions');
    }
};
