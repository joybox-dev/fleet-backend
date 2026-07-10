<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('violations', function (Blueprint $table) {
            $table->string('split_mode')->default('percentage')->after('is_driver_liable'); // percentage, manual
            $table->decimal('driver_share', 10, 3)->nullable()->after('split_mode');
            $table->decimal('contract_share', 10, 3)->nullable()->after('driver_share');
            $table->unsignedBigInteger('charge_contract_id')->nullable()->after('contract_share');
            $table->text('manual_audit_reason')->nullable()->after('charge_contract_id');
            $table->boolean('is_driver_override')->default(false)->after('manual_audit_reason');
            $table->boolean('is_contract_override')->default(false)->after('is_driver_override');
            $table->text('assignment_override_reason')->nullable()->after('is_contract_override');

            $table->foreign('charge_contract_id')->references('id')->on('contracts')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('violations', function (Blueprint $table) {
            $table->dropForeign(['charge_contract_id']);
            $table->dropColumn([
                'split_mode', 'driver_share', 'contract_share', 'charge_contract_id',
                'manual_audit_reason', 'is_driver_override', 'is_contract_override', 'assignment_override_reason'
            ]);
        });
    }
};
