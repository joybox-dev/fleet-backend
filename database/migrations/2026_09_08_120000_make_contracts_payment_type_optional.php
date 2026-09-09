<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `payment_type` was the contract's payment method before pricing moved to per-vehicle-type rules;
 * neither engine reads it, every live contract holds «per_order», and the form still sends it. It
 * stops being demanded of a contract that states its pricing in the rules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('payment_type')->default('per_order')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('payment_type')->default(null)->nullable(false)->change();
        });
    }
};
