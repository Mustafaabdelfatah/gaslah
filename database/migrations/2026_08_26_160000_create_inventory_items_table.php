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
     *
     * A manual stock item. There is no automatic movement: quantity is a manual figure
     * the manager edits, and lowStock (quantity <= reorder_level) is computed, not stored.
     */
    public function up(): void
    {
        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('name', 200);
            $table->string('sku', 80)->nullable();
            $table->decimal('cost', 14, 2)->default(0);
            $table->decimal('quantity', 14, 2)->default(0);
            $table->decimal('reorder_level', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['branch_id', 'is_active']);
        });

        $this->addCheck('inventory_items', 'inventory_items_non_negative', '`cost` >= 0 AND `quantity` >= 0 AND `reorder_level` >= 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
