<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['branch_id', 'archived_at', 'created_at'], 'orders_branch_archived_created_index');
            $table->index(['branch_id', 'archived_at', 'payment_status', 'id'], 'orders_branch_archived_payment_id_index');
            $table->index(['branch_id', 'customer_id', 'created_at'], 'orders_branch_customer_created_index');
        });

        Schema::table('delivery_requests', function (Blueprint $table) {
            $table->index(['branch_id', 'type', 'status', 'completed_at'], 'delivery_branch_type_status_completed_index');
            $table->index(['branch_id', 'type', 'order_id', 'created_at'], 'delivery_branch_type_order_created_index');
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->index(['organization_id', 'created_at'], 'customers_organization_created_index');
            $table->index(['organization_id', 'phone'], 'customers_organization_phone_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropIndex('customers_organization_created_index');
            $table->dropIndex('customers_organization_phone_index');
        });

        Schema::table('delivery_requests', function (Blueprint $table) {
            $table->dropIndex('delivery_branch_type_status_completed_index');
            $table->dropIndex('delivery_branch_type_order_created_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_branch_archived_created_index');
            $table->dropIndex('orders_branch_archived_payment_id_index');
            $table->dropIndex('orders_branch_customer_created_index');
        });
    }
};
