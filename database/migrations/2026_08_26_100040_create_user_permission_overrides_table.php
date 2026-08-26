<?php

use App\Enum\Tenancy\StaffPermissionEnum;
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
        // The presence of a row is itself the signal: an override replaces the role
        // defaults wholesale rather than adding to them. Three states are therefore
        // representable — no row (role defaults apply), a row with no items (an
        // explicit grant of nothing), and a row with items (the explicit set).
        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('user_permission_override_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_permission_override_id');
            $table->string('permission');

            // Named explicitly: the generated names would exceed MySQL's 64 character
            // identifier limit.
            $table->foreign('user_permission_override_id', 'permission_override_items_override_fk')
                ->references('id')
                ->on('user_permission_overrides')
                ->cascadeOnDelete();

            $table->unique(['user_permission_override_id', 'permission'], 'permission_override_items_unique');
        });

        $this->addEnumCheck('user_permission_override_items', 'permission', StaffPermissionEnum::values());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_permission_override_items');
        Schema::dropIfExists('user_permission_overrides');
    }
};
