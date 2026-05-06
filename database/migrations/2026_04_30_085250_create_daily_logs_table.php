<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('daily_logs', function (Blueprint $table) {
            $table->id();

            // Core relations — from meeting: driver + vehicle + contract + date
            $table->foreignId('employee_id')->constrained()->restrictOnDelete();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('contract_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();

            $table->date('log_date');                           // The day being reported
            $table->unsignedInteger('orders_count');           // Number of deliveries
            $table->unsignedInteger('orders_online')->default(0);  // Paid online
            $table->unsignedInteger('orders_cash')->default(0);    // Paid cash (= collected cash)

            // Cash tracking — from meeting: pending cash is dangerous (not company's money)
            $table->decimal('cash_collected', 8, 3)->default(0);   // Total cash from customers
            $table->decimal('cash_settled', 8, 3)->default(0);     // Amount handed to company so far
            $table->decimal('cash_pending', 8, 3)->default(0);     // cash_collected - cash_settled

            // Income calculation — rate × orders (from contract)
            $table->decimal('rate_per_order', 8, 3)->default(0);   // Snapshot from contract at time of entry
            $table->decimal('income_amount', 8, 3)->default(0);    // Total income = orders × rate

            // Odometer — for vehicle tracking
            $table->unsignedInteger('odometer_start')->nullable();
            $table->unsignedInteger('odometer_end')->nullable();

            $table->text('notes')->nullable();

            // ERPNext sync — DailyLog → Sales Invoice
            $table->string('erp_id')->nullable()->index();         // ERPNext Sales Invoice name
            $table->timestamp('erp_synced_at')->nullable();
            $table->enum('erp_sync_status', ['pending', 'synced', 'failed'])->default('pending');

            $table->timestamps();
            $table->softDeletes();

            // Prevent duplicate entries per driver+vehicle+date
            $table->unique(['employee_id', 'vehicle_id', 'log_date']);
            $table->index('log_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_logs');
    }
};
