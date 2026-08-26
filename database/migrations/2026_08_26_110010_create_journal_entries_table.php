<?php

use App\Enum\Accounting\JournalSourceEnum;
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
        // A balanced journal entry header. Written only through the posting service,
        // which verifies the balance, assigns entry_no, and enforces idempotency.
        Schema::create('journal_entries', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();

            // Sequential per organization. A unique index guards it; collisions under
            // concurrency are retried by the posting service.
            $table->unsignedInteger('entry_no');

            // The date of the source document, not the moment of posting, so a
            // backfilled historical entry lands in the correct accounting period.
            $table->date('date');
            $table->string('memo', 255)->nullable();
            $table->string('source');
            $table->string('ref_type', 40)->nullable();

            // String because it may be composite, e.g. "assetId:YYYY-MM" for the
            // period-scoped depreciation guard.
            $table->string('ref_id', 120)->nullable();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['organization_id', 'entry_no']);
            $table->index(['organization_id', 'date']);
            $table->index(['organization_id', 'source', 'ref_type']);
            $table->index('branch_id');
        });

        // The idempotency guard: re-posting the same (organization, source, ref_type,
        // ref_id) returns the existing entry instead of writing a duplicate. Manual
        // entries carry a null ref_id and are exempt, since they may legitimately repeat.
        $this->addIdempotencyIndex();

        $this->addEnumCheck('journal_entries', 'source', JournalSourceEnum::values());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('journal_entries');
    }

    private function addIdempotencyIndex(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            // NULL ref_id collapses the composite to NULL, which MySQL treats as
            // distinct — so manual entries are naturally exempt from the guard.
            DB::statement(
                'ALTER TABLE `journal_entries` ADD COLUMN `idempotency_key` VARCHAR(255) GENERATED ALWAYS AS '
                .'(CASE WHEN `ref_id` IS NULL THEN NULL ELSE CONCAT(`organization_id`, ":", `source`, ":", '
                .'COALESCE(`ref_type`, ""), ":", `ref_id`) END) VIRTUAL, '
                .'ADD UNIQUE `journal_entries_idempotency_unique` (`idempotency_key`)'
            );

            return;
        }

        DB::statement(
            'CREATE UNIQUE INDEX journal_entries_idempotency_unique ON journal_entries '
            .'(organization_id, source, ref_type, ref_id) WHERE ref_id IS NOT NULL'
        );
    }
};
