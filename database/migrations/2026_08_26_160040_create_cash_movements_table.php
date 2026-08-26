<?php

use App\Enum\Reports\CashMovementTypeEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * Run the migrations.
     *
     * A cash paid into or out of the drawer during a shift.
     */
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->decimal('amount', 14, 2);
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('shift_id');
        });

        $this->addEnumCheck('cash_movements', 'type', CashMovementTypeEnum::values());
        $this->addCheck('cash_movements', 'cash_movements_amount_positive', '`amount` > 0');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
