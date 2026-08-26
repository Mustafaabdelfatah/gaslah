<?php

use App\Enum\Messaging\WaCategoryEnum;
use App\Trait\Global\HasDatabaseConstraints;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    use HasDatabaseConstraints;

    public function up(): void
    {
        Schema::create('wa_templates', function (Blueprint $table) {
            $table->id();
            // Null org = a platform default template.
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('category');
            $table->string('event_key', 40)->nullable();
            $table->text('body');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'event_key', 'is_active']);
        });

        $this->addEnumCheck('wa_templates', 'category', WaCategoryEnum::values());
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_templates');
    }
};
