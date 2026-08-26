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
        // Structured delivery addresses a customer manages from the portal.
        Schema::create('customer_addresses', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('district')->nullable();
            $table->string('street')->nullable();
            $table->string('details')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('created_at')->nullable();

            $table->index('customer_id');
        });

        // At most one default address per customer.
        $this->addSingleDefaultIndex();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_addresses');
    }

    private function addSingleDefaultIndex(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `customer_addresses` ADD COLUMN `default_uniq` BIGINT UNSIGNED GENERATED ALWAYS AS '
                .'(CASE WHEN `is_default` = 1 THEN `customer_id` ELSE NULL END) VIRTUAL, '
                .'ADD UNIQUE `customer_addresses_single_default` (`default_uniq`)'
            );

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX customer_addresses_single_default ON customer_addresses (customer_id) WHERE is_default = 1'
        );
    }
};
