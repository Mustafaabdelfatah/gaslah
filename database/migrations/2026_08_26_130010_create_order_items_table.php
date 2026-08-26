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
        // A priced line. The unit price is re-derived from the catalog at creation,
        // never taken from the client. Lines are immutable — an order is recreated,
        // not edited.
        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->restrictOnDelete();
            $table->foreignId('garment_type_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_express')->default(false);
            $table->decimal('quantity', 12, 2);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 14, 2);
            $table->string('notes', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('order_id');
            $table->index('service_id');
        });

        $this->addCheck('order_items', 'order_items_quantity_valid', '`quantity` > 0 AND `quantity` <= 100000');
        $this->addCheck('order_items', 'order_items_amounts_valid', '`unit_price` >= 0 AND `line_total` >= 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
