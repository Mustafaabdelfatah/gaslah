<?php

use App\Enum\Messaging\WaCategoryEnum;
use App\Enum\Messaging\WaMessageStatusEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    public function up(): void
    {
        Schema::create('wa_messages', function (Blueprint $table) {
            $table->id();
            // Nullable: a platform-level message (e.g. driver OTP) carries no org.
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->string('to_phone', 32);
            $table->string('channel', 20)->default('whatsapp');
            $table->string('category');
            $table->string('event_key', 40);
            $table->foreignId('template_id')->nullable();
            $table->text('body');
            $table->string('sender_mode', 20)->default('platform');
            $table->string('status')->default(WaMessageStatusEnum::Queued->value);
            $table->string('provider_message_id')->nullable();
            $table->string('error', 500)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('created_at')->nullable();

            // The quota count reads (org, status, created_at) and (branch, status, created_at).
            $table->index(['organization_id', 'status', 'created_at']);
            $table->index(['branch_id', 'status', 'created_at']);
            $table->index('provider_message_id');
        });

        $this->addEnumCheck('wa_messages', 'status', WaMessageStatusEnum::values());
        $this->addEnumCheck('wa_messages', 'category', WaCategoryEnum::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_messages');
    }
};
