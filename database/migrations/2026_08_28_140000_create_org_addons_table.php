<?php

use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * A paid capability an organization holds *above* its plan — delivery bought on its
     * own, say, by a tenant whose plan does not include it.
     *
     * One row per organization and key, so buying the same add-on twice tops up the row
     * rather than creating a second one nothing would reconcile.
     *
     * No timestamps: what matters is when the add-on started and when it lapses, and both
     * are their own columns.
     */
    public function up(): void
    {
        Schema::create('org_addons', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            // A gated feature key. Not constrained to a table: the catalogue lives in the
            // features table, but an add-on for a key later withdrawn should stay on the
            // record rather than vanish from the tenant's billing history.
            $table->string('key', 60);

            $table->boolean('is_active')->default(true);
            $table->decimal('price_monthly', 12, 2)->default(0);

            $table->timestamp('activated_at')->nullable();
            // Null means it runs until switched off.
            $table->timestamp('expires_at')->nullable();

            $table->unique(['organization_id', 'key']);
            // Resolving a tenant's entitlements reads exactly this.
            $table->index(['organization_id', 'is_active']);
        });

        $this->addCheck('org_addons', 'org_addon_price_valid', '`price_monthly` >= 0');
    }

    public function down(): void
    {
        Schema::dropIfExists('org_addons');
    }
};
