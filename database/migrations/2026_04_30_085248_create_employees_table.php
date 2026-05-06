<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();

            // Identity
            $table->string('name');
            $table->string('name_ar')->nullable();
            $table->string('employee_number')->unique()->nullable();
            $table->string('nationality')->nullable();
            $table->string('civil_id')->nullable()->unique();
            $table->string('phone')->nullable();
            $table->enum('gender', ['male', 'female'])->default('male');
            $table->date('date_of_birth')->nullable();
            $table->date('date_of_joining');

            // Type — from meeting: overseas vs internal transfer
            $table->enum('employee_type', ['overseas', 'local_transfer'])->default('overseas');

            // Status — from meeting: active, inactive (left), on_leave
            $table->enum('status', ['active', 'inactive', 'on_leave', 'probation'])->default('probation');
            $table->string('status_reason')->nullable();         // Why inactive/on_leave
            $table->date('status_changed_at')->nullable();

            // Probation — 3 months from meeting
            $table->date('probation_end_date')->nullable();

            // Payroll — DUAL SALARY from meeting (critical!)
            $table->enum('pay_type', ['fixed', 'per_order', 'hybrid'])->default('fixed');
            $table->decimal('official_salary', 8, 3)->default(0);   // Registered in Ministry — paid via bank
            $table->decimal('actual_salary', 8, 3)->default(0);     // Full amount — paid (bank + cash)
            $table->decimal('rate_per_order', 8, 3)->default(0);    // KWD per order (for per_order/hybrid)
            $table->boolean('has_end_of_service')->default(false);  // From meeting: tick box

            // Documents with expiry — from meeting (all mandatory with alerts)
            $table->date('health_card_expiry')->nullable();          // كرت صحي — annual, mandatory for food
            $table->date('residence_expiry')->nullable();            // إقامة — annual
            $table->date('driving_license_expiry')->nullable();      // رخصة قيادة
            $table->date('work_permit_expiry')->nullable();          // إذن عمل

            // Overseas onboarding stages
            $table->boolean('stage_arrived')->default(false);
            $table->boolean('stage_medical_done')->default(false);
            $table->date('stage_medical_date')->nullable();
            $table->boolean('stage_work_permit_done')->default(false);
            $table->date('stage_work_permit_date')->nullable();
            $table->boolean('stage_driving_trial_done')->default(false);
            $table->boolean('stage_license_obtained')->default(false);
            $table->date('stage_license_date')->nullable();

            $table->text('notes')->nullable();

            // ERPNext sync
            $table->string('erp_id')->nullable()->index();   // ERPNext Employee name (HR-EMP-XXXXX)
            $table->timestamp('erp_synced_at')->nullable();
            $table->enum('erp_sync_status', ['pending', 'synced', 'failed'])->default('pending');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
