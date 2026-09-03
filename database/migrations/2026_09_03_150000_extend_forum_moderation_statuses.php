<?php

use App\Enum\Forum\ForumStatusEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    use HasDatabaseConstraints;

    public function up(): void
    {
        $this->dropCheck('forum_threads', 'forum_threads_status_check');
        $this->dropCheck('forum_posts', 'forum_posts_status_check');

        $this->addEnumCheck('forum_threads', 'status', ForumStatusEnum::values());
        $this->addEnumCheck('forum_posts', 'status', ForumStatusEnum::values());
    }

    public function down(): void
    {
        $this->dropCheck('forum_threads', 'forum_threads_status_check');
        $this->dropCheck('forum_posts', 'forum_posts_status_check');

        DB::table('forum_threads')
            ->whereIn('status', [ForumStatusEnum::Rejected->value, ForumStatusEnum::Hidden->value])
            ->update(['status' => ForumStatusEnum::Pending->value, 'rejection_reason' => null]);
        DB::table('forum_posts')
            ->whereIn('status', [ForumStatusEnum::Rejected->value, ForumStatusEnum::Hidden->value])
            ->update(['status' => ForumStatusEnum::Pending->value]);

        $legacy = [ForumStatusEnum::Pending->value, ForumStatusEnum::Approved->value];
        $this->addEnumCheck('forum_threads', 'status', $legacy);
        $this->addEnumCheck('forum_posts', 'status', $legacy);
    }
};
