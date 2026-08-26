<?php

use App\Enum\Orders\OrderStatusEnum;
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
        // Audit trail for automatic status transitions (the automation sweeper).
        // Manual transitions are recorded in the activity log; user_id is null here.
        Schema::create('order_status_histories', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_cuid')->nullable()->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->string('note', 255)->nullable();
            $table->timestamp('at')->nullable();

            $table->index(['order_id', 'at']);
        });

        $this->addEnumCheck('order_status_histories', 'from_status', OrderStatusEnum::values(), nullable: true);
        $this->addEnumCheck('order_status_histories', 'to_status', OrderStatusEnum::values());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('order_status_histories');
    }
};
