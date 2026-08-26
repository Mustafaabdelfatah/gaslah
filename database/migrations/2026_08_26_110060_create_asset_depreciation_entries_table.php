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
        // One depreciation charge per (asset, month). The unique (asset, period) index
        // is the period-idempotency guard: it makes double-charging a month impossible,
        // which a bare asset-id reference could not prevent past the first month.
        Schema::create('asset_depreciation_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('fixed_asset_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7);
            $table->decimal('amount', 14, 2);
            $table->foreignId('journal_entry_id')->constrained()->restrictOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->timestamps();

            $table->unique(['fixed_asset_id', 'period']);
            $table->index(['organization_id', 'period']);
        });

        $this->addCheck('asset_depreciation_entries', 'asset_depreciation_amount_positive', '`amount` > 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('asset_depreciation_entries');
    }
};
