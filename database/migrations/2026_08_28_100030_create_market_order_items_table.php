<?php

use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * One line of a market order, holding a snapshot of the product as it was sold.
     *
     * Name, unit and price are copied rather than joined: a supplier re-pricing or renaming
     * a product must not change what a past order says was bought, and the line has to
     * keep reading even if the product is later delisted.
     */
    public function up(): void
    {
        Schema::create('market_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('market_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('market_products')->nullOnDelete();

            $table->string('name');
            $table->string('unit', 40)->nullable();
            $table->decimal('unit_price', 14, 2)->default(0);
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('line_total', 14, 2)->default(0);

            $table->index('order_id');
        });

        $this->addCheck(
            'market_order_items',
            'market_item_amounts_valid',
            '`quantity` > 0 AND `unit_price` >= 0 AND `line_total` >= 0',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('market_order_items');
    }
};
