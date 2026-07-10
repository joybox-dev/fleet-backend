<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('client_collections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('contract_id');
            $table->decimal('amount', 10, 3);
            $table->date('date');
            $table->string('payment_method')->default('bank_transfer'); // bank_transfer, cash, cheque
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->foreign('company_id')->references('id')->on('companies')->onDelete('cascade');
            $table->foreign('contract_id')->references('id')->on('contracts')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_collections');
    }
};
