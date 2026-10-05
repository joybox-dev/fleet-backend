<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a vehicle costs whether it drives or not, for the accountant's vehicle report: the purchase
 * and its depreciation for an owned vehicle, the period a rent or an instalment runs. Rent and the
 * monthly instalment were stored already (`rental_price`, `installment_price`) but nothing knew
 * when they start or stop, and nothing counted them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->decimal('purchase_price', 12, 3)->nullable()->after('installment_price');
            $table->date('purchase_date')->nullable()->after('purchase_price');
            $table->unsignedSmallInteger('useful_life_months')->nullable()->after('purchase_date');
            $table->decimal('salvage_value', 12, 3)->nullable()->after('useful_life_months');
            $table->decimal('monthly_depreciation', 12, 3)->nullable()->after('salvage_value');
            $table->date('rental_start_date')->nullable()->after('monthly_depreciation');
            $table->date('rental_end_date')->nullable()->after('rental_start_date');
            $table->date('installment_start_date')->nullable()->after('rental_end_date');
            $table->date('installment_end_date')->nullable()->after('installment_start_date');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn([
                'purchase_price', 'purchase_date', 'useful_life_months', 'salvage_value', 'monthly_depreciation',
                'rental_start_date', 'rental_end_date', 'installment_start_date', 'installment_end_date',
            ]);
        });
    }
};
