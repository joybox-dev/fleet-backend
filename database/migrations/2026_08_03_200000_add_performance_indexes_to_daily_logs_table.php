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
        Schema::table('daily_logs', function (Blueprint $table) {
            $table->index(['company_id', 'employee_id', 'log_date'], 'daily_logs_comp_emp_date_idx');
            $table->index(['company_id', 'contract_id', 'log_date'], 'daily_logs_comp_contract_date_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('daily_logs', function (Blueprint $table) {
            $table->dropIndex('daily_logs_comp_emp_date_idx');
            $table->dropIndex('daily_logs_comp_contract_date_idx');
        });
    }
};
