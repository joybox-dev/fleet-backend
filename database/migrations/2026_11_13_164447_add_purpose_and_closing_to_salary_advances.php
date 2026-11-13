<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A driver can now hold several advances at once — cash, a phone, a SIM line — each with its own
 * instalments, and one of them can be stopped (the line is cut) or moved to another driver without
 * touching the others (client, 2026-11). Before this a driver could hold one active advance only,
 * so everything was lumped into a single figure nobody could take apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('salary_advances', function (Blueprint $table) {
            // cash | phone | sim | other
            $table->string('purpose', 20)->default('cash')->after('reason');
            // Stopped or moved: the date, the balance that was dropped or moved, and why.
            $table->date('closed_at')->nullable()->after('status');
            $table->decimal('closed_balance', 10, 3)->nullable()->after('closed_at');
            $table->string('closed_reason', 500)->nullable()->after('closed_balance');
            $table->unsignedBigInteger('transferred_from_id')->nullable()->after('closed_reason');
            $table->unsignedBigInteger('transferred_to_id')->nullable()->after('transferred_from_id');
        });
    }

    public function down(): void
    {
        Schema::table('salary_advances', function (Blueprint $table) {
            $table->dropColumn(['purpose', 'closed_at', 'closed_balance', 'closed_reason', 'transferred_from_id', 'transferred_to_id']);
        });
    }
};
