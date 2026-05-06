<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();

            // Identity
            $table->string('plate_number')->unique();            // e.g. "12345-KW"
            $table->string('make')->nullable();                  // Toyota, Hyundai...
            $table->string('model')->nullable();
            $table->year('year')->nullable();
            $table->string('color')->nullable();
            $table->string('vin')->nullable()->unique();         // Chassis number

            // Status — from meeting: available, working, maintenance, idle
            $table->enum('status', ['available', 'working', 'maintenance', 'idle'])->default('available');

            // Odometer — for oil change tracking (every 4000 km from meeting)
            $table->unsignedInteger('odometer_km')->default(0);
            $table->unsignedInteger('last_oil_change_km')->default(0);
            $table->unsignedInteger('oil_change_interval_km')->default(4000);

            // Fuel — from meeting: fixed allowance model (budget model was cancelled)
            $table->decimal('monthly_fuel_allowance', 8, 3)->default(0);  // KWD fixed per month

            // Documents with expiry — from meeting
            $table->date('insurance_expiry')->nullable();         // تأمين السيارة
            $table->date('comprehensive_insurance_expiry')->nullable(); // تأمين شامل
            $table->date('food_authority_license_expiry')->nullable();  // رخصة هيئة الغذاء
            $table->date('next_service_due')->nullable();         // Periodic maintenance date

            $table->text('notes')->nullable();

            // ERPNext sync — Vehicle → Asset
            $table->string('erp_id')->nullable()->index();
            $table->timestamp('erp_synced_at')->nullable();
            $table->enum('erp_sync_status', ['pending', 'synced', 'failed'])->default('pending');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicles');
    }
};
