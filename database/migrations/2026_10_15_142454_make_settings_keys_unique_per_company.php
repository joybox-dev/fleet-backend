<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Settings are per company (the table has company_id and the model is company-scoped), but the
 * key was unique across the whole system: the second company to save a setting another company
 * already had — the WhatsApp business id, say — failed with a duplicate-key error.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique('settings_key_unique');
            $table->unique(['company_id', 'key'], 'settings_company_key_unique');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropUnique('settings_company_key_unique');
            $table->unique('key', 'settings_key_unique');
        });
    }
};
