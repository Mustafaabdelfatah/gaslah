<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One messaging configuration per organization. `config` holds the organization's own
     * switches and (encrypted) custom credentials; `limits` holds the platform-set quotas
     * and allow-lists.
     */
    public function up(): void
    {
        Schema::create('messaging_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('config')->nullable();
            $table->json('limits')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messaging_settings');
    }
};
