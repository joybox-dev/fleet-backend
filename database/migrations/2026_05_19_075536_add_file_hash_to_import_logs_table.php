<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->string('file_hash', 64)->nullable()->after('original_filename');
            $table->integer('rows_skipped_duplicate')->default(0)->after('rows_failed');
            $table->index(['company_id', 'entity_type', 'file_hash']);
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'entity_type', 'file_hash']);
            $table->dropColumn(['file_hash', 'rows_skipped_duplicate']);
        });
    }
};
