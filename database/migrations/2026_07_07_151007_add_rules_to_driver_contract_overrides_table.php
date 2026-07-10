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
        Schema::table('driver_contract_overrides', function (Blueprint $table) {
            $table->string('override_type')->nullable();
            $table->text('custom_pricing_rules')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('driver_contract_overrides', function (Blueprint $table) {
            $table->dropColumn(['override_type', 'custom_pricing_rules']);
        });
    }
};
