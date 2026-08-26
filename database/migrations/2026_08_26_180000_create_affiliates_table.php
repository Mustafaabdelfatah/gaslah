<?php

use App\Enum\Affiliate\CommissionTypeEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    public function up(): void
    {
        Schema::create('affiliates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 200);
            $table->string('email')->unique();
            $table->string('phone', 32)->unique();
            $table->string('code', 40)->unique();
            $table->string('commission_type')->default(CommissionTypeEnum::Percent->value);
            $table->decimal('commission_rate', 8, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });

        $this->addEnumCheck('affiliates', 'commission_type', CommissionTypeEnum::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliates');
    }
};
