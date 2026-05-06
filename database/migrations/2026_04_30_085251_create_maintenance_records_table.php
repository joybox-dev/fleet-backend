<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vehicle_id')->constrained()->restrictOnDelete();
            $table->foreignId('reported_by')->constrained('users')->restrictOnDelete();   // Operator
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete(); // Supervisor

            // Garage & type
            $table->string('garage_name')->nullable();
            $table->enum('maintenance_type', ['accident', 'periodic', 'repair', 'oil_change', 'other']);
            $table->date('maintenance_date');

            // Cost — from meeting: supervisor approves/rejects to prevent price manipulation
            $table->decimal('estimated_cost', 8, 3)->default(0);
            $table->decimal('actual_cost', 8, 3)->default(0);

            // Approval workflow — from meeting
            $table->enum('status', ['pending', 'approved', 'rejected', 'completed'])->default('pending');
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();

            // Driver liability — from meeting: 90% company, unless driver caused it
            $table->boolean('is_driver_liable')->default(false);
            $table->foreignId('liable_employee_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->decimal('driver_deduction', 8, 3)->default(0); // Amount deducted from driver

            // Photos — from meeting: driver photos accident, maintenance uploads invoice
            $table->json('photo_paths')->nullable();            // Array of photo paths
            $table->string('invoice_path')->nullable();         // Garage invoice photo

            // Odometer at time of service
            $table->unsignedInteger('odometer_km')->nullable();

            $table->text('notes')->nullable();

            // ERPNext sync — Maintenance → Journal Entry (expense)
            $table->string('erp_id')->nullable()->index();
            $table->timestamp('erp_synced_at')->nullable();
            $table->enum('erp_sync_status', ['pending', 'synced', 'failed'])->default('pending');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_records');
    }
};
