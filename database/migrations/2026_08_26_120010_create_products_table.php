<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // A priceable garment/product under a category. The optional code is edited
        // behind its own fine-grained permission and must be unique within the org.
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained('service_categories')->restrictOnDelete();
            $table->string('name');
            $table->string('name_en')->nullable();
            $table->string('icon')->nullable();
            $table->string('code')->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('category_id');
            $table->index(['organization_id', 'is_active']);
        });

        $this->addProductCodeUniqueIndex();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }

    private function addProductCodeUniqueIndex(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `products` ADD COLUMN `code_uniq` VARCHAR(255) GENERATED ALWAYS AS '
                .'(CASE WHEN `code` IS NULL OR `code` = "" THEN NULL ELSE CONCAT(`organization_id`, ":", `code`) END) VIRTUAL, '
                .'ADD UNIQUE `products_org_code_unique` (`code_uniq`)'
            );

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX products_org_code_unique ON products (organization_id, code) WHERE code IS NOT NULL'
        );
    }
};
