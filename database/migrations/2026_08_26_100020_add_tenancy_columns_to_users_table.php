<?php

use App\Enum\Tenancy\PlatformRoleEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Links a migrated account to its cuid in the old system.
            $table->string('legacy_cuid')->nullable()->unique()->after('id');

            // Mirror of the highest role across the user's branches. The authoritative
            // value lives on user_branches.role; this column is derived, never edited
            // directly. Null means the account holds no branch membership at all,
            // which is the normal state for a platform-only administrator.
            $table->string('role')->nullable()->after('gender');

            $table->boolean('is_platform_owner')->default(false)->after('role');

            // Null is treated as Owner so an existing platform account keeps full
            // access until a narrower role is assigned deliberately.
            $table->string('platform_role')->nullable()->after('is_platform_owner');

            $table->index('role');
            $table->index('is_platform_owner');
            $table->index('is_active');
        });

        $this->addEnumCheck('users', 'role', StaffRoleEnum::values(), nullable: true);
        $this->addEnumCheck('users', 'platform_role', PlatformRoleEnum::values(), nullable: true);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $this->dropCheck('users', 'users_role_check');
        $this->dropCheck('users', 'users_platform_role_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropIndex(['is_platform_owner']);
            $table->dropIndex(['is_active']);
            $table->dropUnique(['legacy_cuid']);
            $table->dropColumn(['legacy_cuid', 'role', 'is_platform_owner', 'platform_role']);
        });
    }
};
