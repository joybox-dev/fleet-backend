<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_records', function (Blueprint $table) {
            $table->decimal('driver_bearing_percentage', 5, 2)->default(0.00)->after('cost');
            $table->decimal('company_bearing_percentage', 5, 2)->default(100.00)->after('driver_bearing_percentage');
            $table->string('accident_status')->nullable()->after('company_bearing_percentage'); // open, under_review, closed
            $table->text('accident_description')->nullable()->after('accident_status');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_records', function (Blueprint $table) {
            $table->dropColumn([
                'driver_bearing_percentage',
                'company_bearing_percentage',
                'accident_status',
                'accident_description'
            ]);
        });
    }
};
