<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('contracts')) {
            Schema::table('contracts', function (Blueprint $table) {
                if (!Schema::hasColumn('contracts', 'is_validity_enabled')) {
                    $table->boolean('is_validity_enabled')->default(false)->after('is_locked');
                }
            });
        }

        if (Schema::hasTable('daily_logs')) {
            Schema::table('daily_logs', function (Blueprint $table) {
                if (!Schema::hasColumn('daily_logs', 'late_login')) {
                    $table->boolean('late_login')->default(false)->after('shift_valid');
                }
                if (!Schema::hasColumn('daily_logs', 'early_logout')) {
                    $table->boolean('early_logout')->default(false)->after('late_login');
                }
                if (!Schema::hasColumn('daily_logs', 'is_valid')) {
                    $table->boolean('is_valid')->default(true)->after('early_logout');
                }
                if (!Schema::hasColumn('daily_logs', 'zone')) {
                    $table->string('zone')->nullable()->after('is_valid');
                }
            });
        }

        if (Schema::hasTable('payroll_runs')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                // Drop old global unique constraint
                $table->dropUnique(['year', 'month']);
                // Add new tenant-scoped unique constraint
                $table->unique(['company_id', 'year', 'month']);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('contracts')) {
            Schema::table('contracts', function (Blueprint $table) {
                if (Schema::hasColumn('contracts', 'is_validity_enabled')) {
                    $table->dropColumn('is_validity_enabled');
                }
            });
        }

        if (Schema::hasTable('daily_logs')) {
            Schema::table('daily_logs', function (Blueprint $table) {
                if (Schema::hasColumn('daily_logs', 'late_login')) {
                    $table->dropColumn('late_login');
                }
                if (Schema::hasColumn('daily_logs', 'early_logout')) {
                    $table->dropColumn('early_logout');
                }
                if (Schema::hasColumn('daily_logs', 'is_valid')) {
                    $table->dropColumn('is_valid');
                }
                if (Schema::hasColumn('daily_logs', 'zone')) {
                    $table->dropColumn('zone');
                }
            });
        }

        if (Schema::hasTable('payroll_runs')) {
            Schema::table('payroll_runs', function (Blueprint $table) {
                // Drop new tenant-scoped unique constraint
                $table->dropUnique(['company_id', 'year', 'month']);
                // Restore old global unique constraint
                $table->unique(['year', 'month']);
            });
        }
    }
};
