<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which dashboard alerts an organization wants raised. One row per organization; an
     * organization with no row takes the defaults, so nothing has to be seeded.
     *
     * Typed columns rather than a JSON blob: the set is small, closed, and every member is
     * a boolean, so a column each keeps the defaults in the schema where they belong.
     */
    public function up(): void
    {
        Schema::create('organization_notification_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();

            // The master switch: off silences every alert below without losing the choices.
            $table->boolean('is_enabled')->default(true);

            $table->boolean('late_orders')->default(true);
            $table->boolean('delivery_requests')->default(true);
            $table->boolean('ready_orders')->default(true);
            $table->boolean('online_payments')->default(true);

            // Off by default: an unpaid order is the normal state of a deferred sale, so
            // alerting on it would be noise for most laundries.
            $table->boolean('unpaid_orders')->default(false);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_notification_settings');
    }
};
