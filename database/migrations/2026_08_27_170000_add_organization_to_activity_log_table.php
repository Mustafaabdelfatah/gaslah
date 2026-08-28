<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stamp each activity row with the tenant it belongs to.
     *
     * The legacy system had no such column and inferred the tenant by walking every user
     * of every branch on each read — which cannot use an index and quietly widens as staff
     * move between organizations. A real column makes the isolation a WHERE clause the
     * database can enforce, and one an audit reader cannot forget to apply.
     *
     * Nullable because platform-level activity belongs to no tenant.
     */
    public function up(): void
    {
        Schema::table(config('activitylog.table_name'), function (Blueprint $table) {
            $table->unsignedBigInteger('organization_id')->nullable()->after('id');
            $table->index(['organization_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table(config('activitylog.table_name'), function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'created_at']);
            $table->dropColumn('organization_id');
        });
    }
};
