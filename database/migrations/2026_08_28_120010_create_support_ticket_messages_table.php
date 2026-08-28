<?php

use App\Enum\Support\SupportAuthorTypeEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * One message in a support thread.
     *
     * `author_type` is stored rather than derived from who wrote it: an automatic reply
     * has no author at all, and a person's role can change without rewriting who was
     * speaking at the time.
     *
     * Messages are never edited, so there is no updated_at.
     */
    public function up(): void
    {
        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();

            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();

            $table->string('author_type', 20);
            // Null for an automatic reply, and for a message whose author has since gone.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();

            $table->text('body');
            $table->timestamp('created_at')->nullable();

            // A thread is always read oldest-first.
            $table->index(['ticket_id', 'id']);
        });

        $this->addEnumCheck('support_ticket_messages', 'author_type', SupportAuthorTypeEnum::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
    }
};
