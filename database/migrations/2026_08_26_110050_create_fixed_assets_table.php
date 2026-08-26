<?php

use App\Enum\Accounting\AssetCategoryEnum;
use App\Enum\Accounting\AssetStatusEnum;
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
        // A straight-line depreciated fixed asset. Book value is computed
        // (cost − accumulated), never stored.
        Schema::create('fixed_assets', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 120);
            $table->string('category');
            $table->decimal('cost', 14, 2);
            $table->date('purchase_date');
            $table->unsignedInteger('useful_life_months');
            $table->decimal('salvage_value', 14, 2)->default(0);
            $table->string('method')->default('straight_line');
            $table->decimal('accumulated_depreciation', 14, 2)->default(0);
            $table->date('last_depreciation_date')->nullable();
            $table->string('status')->default('active');
            $table->boolean('acquisition_posted')->default(false);
            $table->string('acquisition_paid_from')->nullable();
            $table->foreignId('acquisition_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->string('note', 255)->nullable();
            $table->date('disposed_date')->nullable();
            $table->decimal('disposal_proceeds', 14, 2)->nullable();
            $table->decimal('disposal_gain', 14, 2)->nullable();
            $table->string('disposal_via')->nullable();
            $table->foreignId('disposal_journal_entry_id')->nullable()->constrained('journal_entries')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index('branch_id');
            $table->index(['organization_id', 'category']);
        });

        $this->addEnumCheck('fixed_assets', 'category', AssetCategoryEnum::values());
        $this->addEnumCheck('fixed_assets', 'status', AssetStatusEnum::values());
        $this->addCheck('fixed_assets', 'fixed_assets_cost_valid', '`cost` > 0 AND `salvage_value` >= 0 AND `salvage_value` < `cost`');
        $this->addCheck('fixed_assets', 'fixed_assets_life_valid', '`useful_life_months` BETWEEN 1 AND 600');
        $this->addCheck('fixed_assets', 'fixed_assets_accum_valid', '`accumulated_depreciation` >= 0 AND `accumulated_depreciation` <= `cost` - `salvage_value`');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('fixed_assets');
    }
};
