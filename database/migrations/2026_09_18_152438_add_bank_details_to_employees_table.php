<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where an employee's salary is transferred. The payroll already splits a payment at the
     * registered salary — bank up to it, cash above it — but knew no account to send the bank
     * side to, so the monthly transfer list was kept by hand outside the system.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            // Stored without spaces, upper case. 34 is the longest IBAN any country issues.
            $table->string('iban', 34)->nullable()->after('official_salary');
            $table->string('bank_name', 100)->nullable()->after('iban');
            $table->index(['company_id', 'iban']);
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropIndex(['company_id', 'iban']);
            $table->dropColumn(['iban', 'bank_name']);
        });
    }
};
