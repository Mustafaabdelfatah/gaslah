<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('zatca_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('environment', ['sandbox', 'simulation', 'production'])->default('sandbox');
            $table->string('vat_number', 40)->nullable();

            $table->longText('csr_pem')->nullable();
            $table->string('private_key_path')->nullable();
            $table->longText('csid_cert_pem')->nullable();
            $table->string('cert_fingerprint', 64)->nullable();

            $table->longText('compliance_binary_token')->nullable();
            $table->longText('compliance_secret')->nullable();
            $table->string('compliance_request_id')->nullable();
            $table->longText('production_binary_token')->nullable();
            $table->longText('production_secret')->nullable();
            $table->string('production_request_id')->nullable();

            $table->timestamp('complied_at')->nullable();
            $table->timestamp('onboarded_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('zatca_registrations');
    }
};
