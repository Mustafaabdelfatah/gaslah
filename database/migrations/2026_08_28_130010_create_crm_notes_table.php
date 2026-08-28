<?php

use App\Enum\Crm\CrmNoteKindEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * A follow-up entry against a lead or an existing tenant: something that happened, or
     * something to do.
     *
     * Exactly one of the two subjects is set. A note attached to neither belongs nowhere
     * and would never surface again; one attached to both would appear on two timelines
     * saying different things, so the database refuses each.
     */
    public function up(): void
    {
        Schema::create('crm_notes', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();

            $table->foreignId('lead_id')->nullable()->constrained('leads')->cascadeOnDelete();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();

            $table->string('kind', 20)->default(CrmNoteKindEnum::Note->value);
            $table->text('body');

            // A task's deadline, and when it was actually done. Both null on a plain
            // record of something that already happened.
            $table->timestamp('due_at')->nullable();
            $table->timestamp('done_at')->nullable();

            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            // Notes are not edited, so there is no updated_at.
            $table->timestamp('created_at')->nullable();

            // Each subject's timeline, newest first.
            $table->index(['lead_id', 'id']);
            $table->index(['organization_id', 'id']);
            // The open-tasks list, which is what the CRM board is built from.
            $table->index(['done_at', 'due_at']);
        });

        $this->addEnumCheck('crm_notes', 'kind', CrmNoteKindEnum::values());

        $this->addCheck(
            'crm_notes',
            'crm_note_has_exactly_one_subject',
            '(`lead_id` IS NULL) <> (`organization_id` IS NULL)',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_notes');
    }
};
