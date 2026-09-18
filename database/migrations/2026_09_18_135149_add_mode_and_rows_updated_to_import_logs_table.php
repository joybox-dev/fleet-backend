<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An import can now update the records it finds instead of skipping them. The log has to say
     * which kind of run it was, and count what it changed apart from what it created.
     */
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            // create: existing records are skipped. upsert: their filled-in cells are written.
            $table->string('mode', 16)->default('create')->after('entity_type');
            $table->integer('rows_updated')->default(0)->after('rows_imported');
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->dropColumn(['mode', 'rows_updated']);
        });
    }
};
