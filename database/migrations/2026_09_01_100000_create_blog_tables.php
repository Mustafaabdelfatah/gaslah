<?php

use App\Enum\Blog\BlogPostStatusEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * The blog is the platform's own publication — one set of articles every laundry
     * reads, written by the operator. Nothing here is tenant-scoped.
     */
    public function up(): void
    {
        Schema::create('blog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('slug', 200)->unique();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('blog_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->nullable()->constrained('blog_categories')->nullOnDelete();
            $table->string('title', 300);
            // The reader's address for an article, so it must stay unique.
            $table->string('slug', 320)->unique();
            $table->string('excerpt', 500)->nullable();
            $table->longText('content');
            $table->string('cover_image_url', 2000)->nullable();
            $table->json('tags')->nullable();
            $table->string('status')->default(BlogPostStatusEnum::Draft->value);
            // Nullable while a post is a draft, and it may be set forward to schedule one.
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // The reader's query: published, already out, newest first.
            $table->index(['status', 'published_at']);
        });

        $this->addEnumCheck('blog_posts', 'status', BlogPostStatusEnum::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('blog_posts');
        Schema::dropIfExists('blog_categories');
    }
};
