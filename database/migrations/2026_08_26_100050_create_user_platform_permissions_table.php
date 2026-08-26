<?php

use App\Enum\Tenancy\PlatformPermissionEnum;
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
        // Explicit grants for the platform surface. An Owner needs no rows here since
        // that role bypasses permission checks entirely; a Viewer simply has none.
        Schema::create('user_platform_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission');

            $table->unique(['user_id', 'permission']);
        });

        $this->addEnumCheck('user_platform_permissions', 'permission', PlatformPermissionEnum::values());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_platform_permissions');
    }
};
