<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The job title the accountant's payroll report asks for. Nothing held it: `role_category` only
 * says driver or administrative, and an administrative employee's role is his permissions set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('job_title', 100)->nullable()->after('role_category');
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('job_title');
        });
    }
};
