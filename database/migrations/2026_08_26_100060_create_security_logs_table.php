<?php

use App\Enum\Tenancy\SecurityActionEnum;
use App\Enum\Tenancy\SecuritySurfaceEnum;
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
        // Login attempts, kept apart from the general activity log so the lockout
        // window can be counted from a narrow index instead of scanning audit rows.
        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            // Recorded even when no user matched, so a failed attempt against an
            // unknown address still counts toward the lockout.
            $table->string('email')->nullable();
            $table->string('surface');
            $table->string('ip_address', 45)->nullable();
            $table->string('action');
            $table->string('reason')->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['email', 'ip_address', 'surface', 'created_at'], 'security_logs_lockout_index');
            $table->index('user_id');
            $table->index('created_at');
        });

        $this->addEnumCheck('security_logs', 'action', SecurityActionEnum::values());
        $this->addEnumCheck('security_logs', 'surface', SecuritySurfaceEnum::values());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('security_logs');
    }
};
