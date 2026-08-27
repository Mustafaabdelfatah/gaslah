<?php

use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    /**
     * A founding partner: an ownership stake in the platform and a share of its profit.
     *
     * Only active partners count toward the ownership ceiling, so a partner who leaves can
     * be deactivated without their percentage blocking a replacement.
     */
    public function up(): void
    {
        Schema::create('platform_partners', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('role')->nullable();
            $table->string('email')->nullable();
            $table->decimal('ownership_percent', 5, 2)->default(0);
            $table->date('joined_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('notes', 1000)->nullable();
            $table->timestamps();

            $table->index('is_active');
        });

        $this->addCheck(
            'platform_partners',
            'partner_ownership_within_bounds',
            '`ownership_percent` >= 0 AND `ownership_percent` <= 100',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_partners');
    }
};
