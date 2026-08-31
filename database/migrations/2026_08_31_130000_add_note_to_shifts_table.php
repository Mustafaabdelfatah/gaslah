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
        // Whoever counts the drawer writes down why it came up short. The close
        // request already accepted a note; it had nowhere to land.
        Schema::table('shifts', function (Blueprint $table) {
            $table->string('note', 500)->nullable()->after('variance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            $table->dropColumn('note');
        });
    }
};
