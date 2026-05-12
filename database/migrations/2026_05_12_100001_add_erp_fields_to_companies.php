<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // ── ERPNext Mapping ──
            $table->string('erp_company_name')->nullable()->after('settings');   // ERPNext Company doctype name
            $table->string('erp_cost_center')->nullable()->after('erp_company_name');
            $table->string('erp_abbreviation', 10)->nullable()->after('erp_cost_center'); // "FF", "SF"
            $table->json('erp_config')->nullable()->after('erp_abbreviation');   // Per-company account overrides
            $table->string('erp_sync_status')->default('pending')->after('erp_config'); // pending|synced|failed
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'erp_company_name', 'erp_cost_center', 'erp_abbreviation',
                'erp_config', 'erp_sync_status',
            ]);
        });
    }
};
