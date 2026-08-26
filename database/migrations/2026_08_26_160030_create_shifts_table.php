<?php

use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * Run the migrations.
     *
     * A cashier shift. It carries opened_at/closed_at rather than created/updated
     * timestamps. expected_cash is fixed at close; live it is recomputed.
     */
    public function up(): void
    {
        Schema::create('shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->decimal('opening_float', 14, 2)->default(0);
            $table->decimal('expected_cash', 14, 2)->default(0);
            $table->decimal('actual_cash', 14, 2)->nullable();
            $table->decimal('variance', 14, 2)->nullable();

            $table->index(['branch_id', 'opened_at']);
        });

        $this->addCheck('shifts', 'shifts_opening_float_non_negative', '`opening_float` >= 0');

        // One open shift per user (closed_at IS NULL).
        $this->addOpenShiftIndex();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shifts');
    }

    private function addOpenShiftIndex(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement(
                'ALTER TABLE `shifts` ADD COLUMN `open_user_key` BIGINT GENERATED ALWAYS AS '
                .'(CASE WHEN `closed_at` IS NULL THEN `user_id` ELSE NULL END) VIRTUAL, '
                .'ADD UNIQUE `shifts_open_user_unique` (`open_user_key`)'
            );

            return;
        }

        DB::statement('CREATE UNIQUE INDEX shifts_open_user_unique ON shifts (user_id) WHERE closed_at IS NULL');
    }
};
