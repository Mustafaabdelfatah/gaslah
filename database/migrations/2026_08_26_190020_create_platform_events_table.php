<?php

use App\Enum\Platform\PlatformEventTypeEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    public function up(): void
    {
        Schema::create('platform_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('plan_name', 120)->nullable();
            $table->string('cycle', 20)->nullable();
            $table->decimal('monthly', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->timestamp('created_at')->nullable();

            $table->index(['type', 'created_at']);
        });

        $this->addEnumCheck('platform_events', 'type', PlatformEventTypeEnum::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_events');
    }
};
