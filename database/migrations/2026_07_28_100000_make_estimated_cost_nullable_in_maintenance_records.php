<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_records', function (Blueprint $table) {
            $table->decimal('estimated_cost', 8, 3)->nullable()->default(0)->change();
            $table->decimal('actual_cost', 8, 3)->nullable()->default(0)->change();
            $table->decimal('driver_deduction', 8, 3)->nullable()->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_records', function (Blueprint $table) {
            $table->decimal('estimated_cost', 8, 3)->default(0)->change();
            $table->decimal('actual_cost', 8, 3)->default(0)->change();
            $table->decimal('driver_deduction', 8, 3)->default(0)->change();
        });
    }
};
