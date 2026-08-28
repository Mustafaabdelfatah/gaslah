<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tenant's third-party credentials and switches: payment gateway, WhatsApp, SMS.
     *
     * A real table rather than JSON rows in a key/value store, as BRD 13 §7.2 asks of a
     * clean rebuild — the legacy shape needed a LIKE over a text key, which no index can
     * serve. Secrets sit in their own columns so they can be encrypted at rest and, just
     * as importantly, so a query that selects the config can be written not to fetch them.
     */
    public function up(): void
    {
        Schema::create('organization_integrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();

            // Which payment methods the counter may offer.
            $table->json('payment_methods')->nullable();

            $table->string('gateway_provider', 40)->default('stub');
            $table->string('gateway_public_key', 500)->nullable();
            $table->text('gateway_secret_key')->nullable();

            $table->boolean('messaging_enabled')->default(true);

            $table->boolean('whatsapp_enabled')->default(false);
            $table->string('whatsapp_mode', 20)->default('platform');
            $table->text('whatsapp_token')->nullable();
            $table->string('whatsapp_phone_id')->nullable();

            $table->boolean('sms_enabled')->default(false);
            $table->string('sms_provider', 40)->nullable();
            $table->text('sms_api_key')->nullable();
            $table->string('sms_sender', 40)->nullable();

            $table->json('events')->nullable();
            $table->json('templates')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_integrations');
    }
};
