<?php

use App\Enum\Forum\ForumStatusEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * The community forum is platform-wide (not tenant-scoped); a thread's organization_id
     * is attribution only.
     */
    public function up(): void
    {
        Schema::create('forum_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('name_en', 200)->nullable();
            $table->string('slug', 200)->unique();
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('forum_threads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('category_id')->constrained('forum_categories')->cascadeOnDelete();
            $table->string('title', 300);
            $table->string('slug', 320);
            $table->text('body');
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default(ForumStatusEnum::Pending->value);
            $table->string('rejection_reason', 500)->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->boolean('is_closed')->default(false);
            $table->unsignedInteger('reply_count')->default(0);
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'is_pinned', 'last_activity_at']);
            $table->index(['author_id', 'status']);
        });

        Schema::create('forum_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('thread_id')->constrained('forum_threads')->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->string('status')->default(ForumStatusEnum::Approved->value);
            $table->timestamps();

            $table->index(['thread_id', 'status']);
        });

        $this->addEnumCheck('forum_threads', 'status', ForumStatusEnum::values());
        $this->addEnumCheck('forum_posts', 'status', ForumStatusEnum::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('forum_posts');
        Schema::dropIfExists('forum_threads');
        Schema::dropIfExists('forum_categories');
    }
};
