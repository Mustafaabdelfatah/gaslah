<?php

use App\Enum\Catalog\PricingTypeEnum;
use App\Enum\Catalog\ServiceTypeEnum;
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
        // A single price cell = product × service type. Order items reference this,
        // which is why a service is never deleted, only deactivated.
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained('service_categories')->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('service_type');
            $table->string('name');
            $table->string('pricing_type')->default('per_piece');
            $table->decimal('base_price', 14, 2)->default(0);
            $table->decimal('express_surcharge', 14, 2)->default(0);
            $table->boolean('is_express_available')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['product_id', 'service_type']);
            $table->index(['organization_id', 'is_active']);
        });

        $this->addEnumCheck('services', 'service_type', ServiceTypeEnum::values());
        $this->addEnumCheck('services', 'pricing_type', PricingTypeEnum::values());
        $this->addCheck('services', 'services_prices_non_negative', '`base_price` >= 0 AND `express_surcharge` >= 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
