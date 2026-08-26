<?php

use App\Enum\Accounting\AccountTypeEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // The per-organization chart of accounts, a self-referential tree. Balances
        // are never stored here — they are always computed from journal line totals.
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name', 120);
            $table->string('name_en', 120)->nullable();
            $table->string('type');
            $table->foreignId('parent_id')->nullable()->constrained('accounts')->restrictOnDelete();

            // System accounts are seeded automatically and protected from structural
            // edits so the posting engine's wiring stays intact.
            $table->boolean('is_system')->default(false);
            $table->string('system_key', 40)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['organization_id', 'code']);
            $table->index('parent_id');
            $table->index(['organization_id', 'type']);
        });

        // One row per system key per organization, enforced with a generated column so
        // the seeder stays idempotent under concurrency. (MySQL has no partial index;
        // NULL system keys collapse to NULL here and are exempt from the constraint.)
        $this->addSystemKeyUniqueIndex();

        $this->addEnumCheck('accounts', 'type', AccountTypeEnum::values());
        // A direct self-parent (parent_id = id) is prevented in the service layer:
        // MySQL forbids a CHECK constraint that references an auto-increment column.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('accounts');
    }

    private function addSystemKeyUniqueIndex(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `accounts` ADD COLUMN `system_key_uniq` VARCHAR(83) GENERATED ALWAYS AS '
                .'(CASE WHEN `system_key` IS NULL THEN NULL ELSE CONCAT(`organization_id`, ":", `system_key`) END) VIRTUAL, '
                .'ADD UNIQUE `accounts_org_system_key_unique` (`system_key_uniq`)'
            );

            return;
        }

        // SQLite (tests) supports the partial index directly.
        DB::statement(
            'CREATE UNIQUE INDEX accounts_org_system_key_unique ON accounts (organization_id, system_key) '
            .'WHERE system_key IS NOT NULL'
        );
    }
};
