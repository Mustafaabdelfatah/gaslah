<?php

use App\Enum\Support\SupportPriorityEnum;
use App\Enum\Support\SupportTicketStatusEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * A support ticket a tenant raised with the platform.
     *
     * `category` is a column of its own. The legacy system had none and prefixed the
     * subject with the category in brackets, which made the subject unsearchable and the
     * category unfilterable; that constraint belonged to a schema this project does not
     * share.
     *
     * `last_reply_at` is denormalised on purpose: the operator's inbox sorts by it on
     * every load, and deriving it per row from the thread is the classic N+1.
     */
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();

            $table->string('subject', 200);
            $table->string('category', 60)->nullable();
            $table->string('status', 20)->default(SupportTicketStatusEnum::Open->value);
            $table->string('priority', 20)->default(SupportPriorityEnum::Normal->value);

            // Who raised it, and which admin owns it. Both survive the person leaving:
            // a ticket must still read correctly once its author's account is gone.
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('last_reply_at')->nullable();
            $table->timestamps();

            // The operator's inbox: the live queue, newest activity first.
            $table->index(['status', 'last_reply_at']);
            // A tenant's own list, same ordering.
            $table->index(['organization_id', 'last_reply_at']);
            $table->index('assigned_to_id');
        });

        $this->addEnumCheck('support_tickets', 'status', SupportTicketStatusEnum::values());
        $this->addEnumCheck('support_tickets', 'priority', SupportPriorityEnum::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('support_tickets');
    }
};
