<?php

use App\Enum\Market\MarketCategoryEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * A product a market supplier lists.
     *
     * Stock is nullable and means unlimited — a supplier who does not track counts should
     * not have to claim a number.
     */
    public function up(): void
    {
        Schema::create('market_products', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('supplier_id')->constrained('market_suppliers')->cascadeOnDelete();

            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('category', 40);
            $table->string('description', 1000)->nullable();
            $table->string('unit', 40)->default('قطعة');

            $table->decimal('price', 14, 2)->default(0);
            $table->unsignedInteger('stock')->nullable();
            $table->string('image_url', 500)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Browsing is always "active products, in a category, newest first".
            $table->index(['supplier_id', 'is_active']);
            $table->index(['category', 'is_active']);
        });

        $this->addEnumCheck('market_products', 'category', MarketCategoryEnum::values());
        $this->addCheck('market_products', 'market_product_price_valid', '`price` >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('market_products');
    }
};
