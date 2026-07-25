<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Contracts table
        if (Schema::hasTable('contracts')) {
            Schema::table('contracts', function (Blueprint $table) {
                try {
                    $table->dropUnique(['contract_number']);
                } catch (\Throwable $e) {}
            });
        }

        // Vehicles table
        if (Schema::hasTable('vehicles')) {
            Schema::table('vehicles', function (Blueprint $table) {
                try {
                    $table->dropUnique(['plate_number']);
                } catch (\Throwable $e) {}
                try {
                    $table->dropUnique(['vin']);
                } catch (\Throwable $e) {}
            });
        }

        // Employees table
        if (Schema::hasTable('employees')) {
            Schema::table('employees', function (Blueprint $table) {
                try {
                    $table->dropUnique(['employee_number']);
                } catch (\Throwable $e) {}
                try {
                    $table->dropUnique(['civil_id']);
                } catch (\Throwable $e) {}
            });
        }
    }

    public function down(): void
    {
        // No-op
    }
};
