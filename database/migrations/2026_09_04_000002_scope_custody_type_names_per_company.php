<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * custody_types was created before the app was multi-tenant, so its name was made unique across the
 * whole installation. company_id was bolted on later without revisiting the index, which left every
 * company sharing one namespace: the second company to add a custody type called "هاتف" got a raw
 * 1062 duplicate-key error, and no name the first company had taken was ever available again.
 *
 * The name is unique per company from here on. The table does not soft-delete, so a deleted name is
 * genuinely free for reuse and deleted_at does not need to be part of the key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custody_types', function (Blueprint $table) {
            $table->dropUnique('custody_types_name_unique');
            $table->unique(['company_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('custody_types', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'name']);
            $table->unique('name');
        });
    }
};
