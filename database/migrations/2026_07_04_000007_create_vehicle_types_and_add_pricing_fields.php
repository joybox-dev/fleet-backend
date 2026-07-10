<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('vehicle_types')) {
            Schema::create('vehicle_types', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('company_id');
                $table->string('name');
                $table->string('name_ar');
                $table->timestamps();

                $table->foreign('company_id')->references('id')->on('companies')->onDelete('restrict');
            });
        }

        Schema::table('contracts', function (Blueprint $table) {
            if (!Schema::hasColumn('contracts', 'vehicle_type_id')) {
                $table->unsignedBigInteger('vehicle_type_id')->nullable()->after('notes');
                $table->foreign('vehicle_type_id')->references('id')->on('vehicle_types')->onDelete('restrict');
            }
            if (!Schema::hasColumn('contracts', 'client_payment_method')) {
                $table->string('client_payment_method')->nullable()->after('vehicle_type_id');
            }
            if (!Schema::hasColumn('contracts', 'client_pricing_rules')) {
                $table->text('client_pricing_rules')->nullable()->after('client_payment_method');
            }
            if (!Schema::hasColumn('contracts', 'driver_payment_method')) {
                $table->string('driver_payment_method')->nullable()->after('client_pricing_rules');
            }
            if (!Schema::hasColumn('contracts', 'driver_pricing_rules')) {
                $table->text('driver_pricing_rules')->nullable()->after('driver_payment_method');
            }
            if (!Schema::hasColumn('contracts', 'capacity_target')) {
                $table->integer('capacity_target')->nullable()->after('driver_pricing_rules');
            }
            if (!Schema::hasColumn('contracts', 'capacity_pricing_rules')) {
                $table->text('capacity_pricing_rules')->nullable()->after('capacity_target');
            }
        });

        Schema::table('vehicles', function (Blueprint $table) {
            if (!Schema::hasColumn('vehicles', 'vehicle_type_id')) {
                $table->unsignedBigInteger('vehicle_type_id')->nullable()->after('status');
                $table->foreign('vehicle_type_id')->references('id')->on('vehicle_types')->onDelete('restrict');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            if (Schema::hasColumn('vehicles', 'vehicle_type_id')) {
                // SQLite doesn't support dropping foreign keys easily, so we just drop the column
                $table->dropColumn('vehicle_type_id');
            }
        });

        Schema::table('contracts', function (Blueprint $table) {
            $cols = [];
            if (Schema::hasColumn('contracts', 'vehicle_type_id')) $cols[] = 'vehicle_type_id';
            if (Schema::hasColumn('contracts', 'client_payment_method')) $cols[] = 'client_payment_method';
            if (Schema::hasColumn('contracts', 'client_pricing_rules')) $cols[] = 'client_pricing_rules';
            if (Schema::hasColumn('contracts', 'driver_payment_method')) $cols[] = 'driver_payment_method';
            if (Schema::hasColumn('contracts', 'driver_pricing_rules')) $cols[] = 'driver_pricing_rules';
            if (Schema::hasColumn('contracts', 'capacity_target')) $cols[] = 'capacity_target';
            if (Schema::hasColumn('contracts', 'capacity_pricing_rules')) $cols[] = 'capacity_pricing_rules';
            
            if (!empty($cols)) {
                $table->dropColumn($cols);
            }
        });

        Schema::dropIfExists('vehicle_types');
    }
};
