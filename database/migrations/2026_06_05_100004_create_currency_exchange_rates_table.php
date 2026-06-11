<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('currency_exchange_rates', function (Blueprint $table) {
            $table->id();
            
            // Tenant isolation
            $table->unsignedBigInteger('company_id');
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            
            $table->string('from_currency');
            $table->string('to_currency');
            $table->decimal('exchange_rate', 10, 6);
            $table->integer('year');
            $table->integer('month');
            
            $table->timestamps();
            
            $table->unique(['company_id', 'from_currency', 'to_currency', 'year', 'month'], 'uidx_currency_period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_exchange_rates');
    }
};
