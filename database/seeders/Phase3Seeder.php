<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Models\MaintenanceRecord;
use App\Models\CashSettlement;
use App\Models\DailyLog;

class Phase3Seeder extends Seeder
{
    public function run(): void
    {
        $operator = User::where('email', 'op1@fleetops.com')->first();
        $supervisor = User::where('email', 'supervisor@fleetops.com')->first();

        $emp1 = Employee::where('employee_number', 'EMP-001')->first();
        $emp2 = Employee::where('employee_number', 'EMP-002')->first();
        $emp3 = Employee::where('employee_number', 'EMP-003')->first();
        $emp4 = Employee::where('employee_number', 'EMP-004')->first();

        $v1 = Vehicle::where('plate_number', '11234-KW')->first();
        $v2 = Vehicle::where('plate_number', '11235-KW')->first();
        $v3 = Vehicle::where('plate_number', '22001-KW')->first();
        $v4 = Vehicle::where('plate_number', '22002-KW')->first();
        $v6 = Vehicle::where('plate_number', '33011-KW')->first(); // maintenance vehicle

        // ── Violations (6 tickets) ──
        $violations = [
            ['employee_id' => $emp1->id, 'vehicle_id' => $v1->id, 'created_by' => $operator->id, 'violation_date' => now()->subDays(20)->toDateString(), 'violation_type' => 'تجاوز سرعة', 'reference_number' => 'VIO-2026-0001', 'amount' => 15.000, 'is_driver_liable' => true],
            ['employee_id' => $emp1->id, 'vehicle_id' => $v1->id, 'created_by' => $operator->id, 'violation_date' => now()->subDays(10)->toDateString(), 'violation_type' => 'وقوف خاطئ', 'reference_number' => 'VIO-2026-0002', 'amount' => 10.000, 'is_driver_liable' => true],
            ['employee_id' => $emp2->id, 'vehicle_id' => $v2->id, 'created_by' => $operator->id, 'violation_date' => now()->subDays(15)->toDateString(), 'violation_type' => 'قطع إشارة', 'reference_number' => 'VIO-2026-0003', 'amount' => 50.000, 'is_driver_liable' => true],
            ['employee_id' => $emp3->id, 'vehicle_id' => $v3->id, 'created_by' => $operator->id, 'violation_date' => now()->subDays(5)->toDateString(), 'violation_type' => 'تجاوز سرعة', 'reference_number' => 'VIO-2026-0004', 'amount' => 20.000, 'is_driver_liable' => false, 'notes' => 'خطأ كاميرا - الشركة تتحمل'],
            ['employee_id' => $emp4->id, 'vehicle_id' => $v4->id, 'created_by' => $operator->id, 'violation_date' => now()->subDays(2)->toDateString(), 'violation_type' => 'عدم ربط حزام', 'reference_number' => 'VIO-2026-0005', 'amount' => 10.000, 'is_driver_liable' => true],
            ['employee_id' => $emp2->id, 'vehicle_id' => $v2->id, 'created_by' => $operator->id, 'violation_date' => now()->subDays(1)->toDateString(), 'violation_type' => 'وقوف خاطئ', 'reference_number' => 'VIO-2026-0006', 'amount' => 10.000, 'is_driver_liable' => true],
        ];
        foreach ($violations as $vi) {
            Violation::firstOrCreate(['reference_number' => $vi['reference_number']], $vi);
        }
        $this->command->info('✓ 6 violations seeded (5 driver liable, 1 company)');

        // ── Maintenance Records (all statuses) ──
        $records = [
            // Completed - oil change
            ['vehicle_id' => $v1->id, 'reported_by' => $operator->id, 'approved_by' => $supervisor->id, 'garage_name' => 'ورشة الصفا', 'maintenance_type' => 'oil_change', 'maintenance_date' => now()->subDays(30)->toDateString(), 'estimated_cost' => 12.000, 'actual_cost' => 12.000, 'status' => 'completed', 'approved_at' => now()->subDays(29), 'odometer_km' => 44000, 'notes' => 'تغيير زيت دوري'],
            // Approved - repair
            ['vehicle_id' => $v2->id, 'reported_by' => $operator->id, 'approved_by' => $supervisor->id, 'garage_name' => 'مركز الخليج للصيانة', 'maintenance_type' => 'repair', 'maintenance_date' => now()->subDays(5)->toDateString(), 'estimated_cost' => 45.000, 'actual_cost' => 42.500, 'status' => 'approved', 'approved_at' => now()->subDays(4), 'odometer_km' => 38700],
            // Pending - waiting supervisor
            ['vehicle_id' => $v3->id, 'reported_by' => $operator->id, 'garage_name' => 'ورشة النور', 'maintenance_type' => 'periodic', 'maintenance_date' => now()->subDays(1)->toDateString(), 'estimated_cost' => 25.000, 'status' => 'pending', 'odometer_km' => 62100, 'notes' => 'صيانة دورية 60 ألف'],
            // Rejected
            ['vehicle_id' => $v4->id, 'reported_by' => $operator->id, 'approved_by' => $supervisor->id, 'garage_name' => 'ورشة السالم', 'maintenance_type' => 'repair', 'maintenance_date' => now()->subDays(8)->toDateString(), 'estimated_cost' => 120.000, 'status' => 'rejected', 'rejection_reason' => 'السعر مبالغ فيه - يرجى الحصول على عرض من ورشة أخرى', 'odometer_km' => 51300],
            // Accident - driver liable
            ['vehicle_id' => $v6->id, 'reported_by' => $operator->id, 'approved_by' => $supervisor->id, 'garage_name' => 'ورشة الخبراء', 'maintenance_type' => 'accident', 'maintenance_date' => now()->subDays(3)->toDateString(), 'estimated_cost' => 85.000, 'actual_cost' => 90.000, 'status' => 'approved', 'approved_at' => now()->subDays(2), 'is_driver_liable' => true, 'liable_employee_id' => $emp3->id, 'driver_deduction' => 45.000, 'odometer_km' => 12500, 'notes' => 'حادث اصطدام - السائق مسؤول 50%'],
            // Completed oil change
            ['vehicle_id' => $v2->id, 'reported_by' => $operator->id, 'approved_by' => $supervisor->id, 'garage_name' => 'محل زيوت الكويت', 'maintenance_type' => 'oil_change', 'maintenance_date' => now()->subDays(45)->toDateString(), 'estimated_cost' => 14.000, 'actual_cost' => 14.000, 'status' => 'completed', 'approved_at' => now()->subDays(44), 'odometer_km' => 36000],
        ];
        foreach ($records as $r) {
            MaintenanceRecord::firstOrCreate(
                ['vehicle_id' => $r['vehicle_id'], 'maintenance_date' => $r['maintenance_date'], 'maintenance_type' => $r['maintenance_type']],
                $r
            );
        }
        $this->command->info('✓ 6 maintenance records seeded (2 completed, 2 approved, 1 pending, 1 rejected)');

        // ── Cash Settlements (partial settlements for pending cash) ──
        $logsWithPending = DailyLog::where('cash_pending', '>', 0)->take(5)->get();
        $settleCount = 0;
        foreach ($logsWithPending as $log) {
            $amount = round($log->cash_pending * rand(40, 70) / 100, 3);
            CashSettlement::firstOrCreate(
                ['employee_id' => $log->employee_id, 'daily_log_id' => $log->id],
                [
                    'employee_id' => $log->employee_id,
                    'daily_log_id' => $log->id,
                    'received_by' => $operator->id,
                    'settlement_date' => now()->subDays(rand(0, 3))->toDateString(),
                    'amount' => $amount,
                    'notes' => 'تسوية جزئية',
                ]
            );
            $settleCount++;
        }
        $this->command->info("✓ {$settleCount} cash settlements seeded");
    }
}
