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
        Schema::table('employees', function (Blueprint $table) {
            if (!Schema::hasColumn('employees', 'role_category')) {
                $table->string('role_category')->default('driver')->after('status');
            }
            if (!Schema::hasColumn('employees', 'admin_role_id')) {
                $table->unsignedBigInteger('admin_role_id')->nullable()->after('role_category');
            }
            if (!Schema::hasColumn('employees', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->after('admin_role_id');
            }
            if (!Schema::hasColumn('employees', 'salary_allocations')) {
                $table->json('salary_allocations')->nullable()->after('user_id');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            if (Schema::hasColumn('employees', 'role_category')) {
                $table->dropColumn(['role_category', 'admin_role_id', 'user_id', 'salary_allocations']);
            }
        });
    }
};
