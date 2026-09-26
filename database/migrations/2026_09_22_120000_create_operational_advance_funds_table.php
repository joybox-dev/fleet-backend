<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An administrative employee may hold a cash float («رصيد») handed to him by whoever holds the
 * «إعطاء رصيد» permission, and give custodies to other administrative employees out of it.
 * The float is a ledger of hand-overs (positive) and take-backs (negative). A custody names the
 * float it came from, or none when the company handed it over directly, as every custody did
 * before this table existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operational_advance_funds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 3);
            $table->date('date');
            $table->string('notes', 255)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_id', 'employee_id']);
        });

        Schema::table('operational_advances', function (Blueprint $table) {
            $table->unsignedBigInteger('funded_by_employee_id')->nullable()->after('employee_id');
            $table->unsignedBigInteger('created_by')->nullable()->after('approved_by');
            $table->index('funded_by_employee_id');
        });

        // SQLite (the test database) cannot add a foreign key to an existing table.
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('operational_advances', function (Blueprint $table) {
                $table->foreign('funded_by_employee_id')->references('id')->on('employees')->nullOnDelete();
                $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::table('operational_advances', function (Blueprint $table) {
            if (Schema::getConnection()->getDriverName() !== 'sqlite') {
                $table->dropForeign(['funded_by_employee_id']);
                $table->dropForeign(['created_by']);
            }
            $table->dropIndex(['funded_by_employee_id']);
            $table->dropColumn(['funded_by_employee_id', 'created_by']);
        });

        Schema::dropIfExists('operational_advance_funds');
    }
};
