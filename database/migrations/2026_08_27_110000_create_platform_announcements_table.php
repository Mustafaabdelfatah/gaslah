<?php

use App\Enum\Platform\PlatformAnnouncementLevelEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    public function up(): void
    {
        Schema::create('platform_announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->string('level', 20)->default(PlatformAnnouncementLevelEnum::Info->value);
            // null organization = broadcast to every tenant; a value scopes it to one.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['is_active', 'starts_at', 'ends_at']);
            $table->index('organization_id');
        });

        $this->addEnumCheck('platform_announcements', 'level', PlatformAnnouncementLevelEnum::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_announcements');
    }
};
