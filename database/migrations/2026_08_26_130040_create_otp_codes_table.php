<?php

use App\Enum\Global\OtpPurposeEnum;
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
        // A hashed, single-use OTP. Scoped by purpose so a code minted to approve a
        // till payment can never be replayed on another surface.
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 32);
            $table->string('code_hash');
            $table->string('purpose');
            $table->timestamp('expires_at');

            // Set atomically on consumption; a code is spent by the first UPDATE that
            // finds it still null.
            $table->timestamp('consumed_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamps();

            $table->index(['organization_id', 'phone', 'purpose', 'consumed_at']);
        });

        $this->addEnumCheck('otp_codes', 'purpose', OtpPurposeEnum::values());
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('otp_codes');
    }
};
