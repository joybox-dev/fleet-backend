<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\Employee;
use App\Models\Contract;
use App\Models\DailyLog;

class Phase2Seeder extends Seeder
{
    public function run(): void
    {
        // ── Vehicles (8) ──
        $vehicles = [
            ['plate_number' => '11234-KW', 'make' => 'Toyota', 'model' => 'Hilux', 'year' => 2024, 'color' => 'White', 'vin' => 'JTFHX02P600000001', 'status' => 'working', 'odometer_km' => 45200, 'last_oil_change_km' => 44000, 'monthly_fuel_allowance' => 40.000, 'insurance_expiry' => '2026-09-15', 'comprehensive_insurance_expiry' => '2026-09-15', 'food_authority_license_expiry' => '2026-11-01', 'next_service_due' => '2026-06-01'],
            ['plate_number' => '11235-KW', 'make' => 'Toyota', 'model' => 'Hilux', 'year' => 2024, 'color' => 'White', 'vin' => 'JTFHX02P600000002', 'status' => 'working', 'odometer_km' => 38700, 'last_oil_change_km' => 36000, 'monthly_fuel_allowance' => 40.000, 'insurance_expiry' => '2026-10-20', 'comprehensive_insurance_expiry' => '2026-10-20', 'food_authority_license_expiry' => '2027-01-15', 'next_service_due' => '2026-05-15'],
            ['plate_number' => '22001-KW', 'make' => 'Hyundai', 'model' => 'Accent', 'year' => 2023, 'color' => 'Silver', 'vin' => 'KMHCT4AE0NU000003', 'status' => 'working', 'odometer_km' => 62100, 'last_oil_change_km' => 60000, 'monthly_fuel_allowance' => 35.000, 'insurance_expiry' => '2026-07-01', 'comprehensive_insurance_expiry' => '2026-07-01', 'food_authority_license_expiry' => '2026-08-20', 'next_service_due' => '2026-05-20'],
            ['plate_number' => '22002-KW', 'make' => 'Hyundai', 'model' => 'Accent', 'year' => 2023, 'color' => 'Blue', 'vin' => 'KMHCT4AE0NU000004', 'status' => 'working', 'odometer_km' => 51300, 'last_oil_change_km' => 48000, 'monthly_fuel_allowance' => 35.000, 'insurance_expiry' => '2027-02-10', 'comprehensive_insurance_expiry' => '2027-02-10', 'food_authority_license_expiry' => '2027-03-01', 'next_service_due' => '2026-07-10'],
            ['plate_number' => '33010-KW', 'make' => 'Nissan', 'model' => 'Sunny', 'year' => 2025, 'color' => 'White', 'vin' => 'MNTBB7A93R0000005', 'status' => 'available', 'odometer_km' => 8200, 'last_oil_change_km' => 8000, 'monthly_fuel_allowance' => 30.000, 'insurance_expiry' => '2027-04-01', 'comprehensive_insurance_expiry' => '2027-04-01', 'food_authority_license_expiry' => '2027-04-01', 'next_service_due' => '2026-10-01'],
            ['plate_number' => '33011-KW', 'make' => 'Nissan', 'model' => 'Sunny', 'year' => 2025, 'color' => 'Grey', 'vin' => 'MNTBB7A93R0000006', 'status' => 'maintenance', 'odometer_km' => 12500, 'last_oil_change_km' => 12000, 'monthly_fuel_allowance' => 30.000, 'insurance_expiry' => '2027-03-15', 'comprehensive_insurance_expiry' => '2027-03-15', 'food_authority_license_expiry' => '2027-03-15', 'next_service_due' => '2026-05-05'],
            ['plate_number' => '44100-KW', 'make' => 'Kia', 'model' => 'Picanto', 'year' => 2022, 'color' => 'Red', 'vin' => 'KNADN512BL0000007', 'status' => 'idle', 'odometer_km' => 89000, 'last_oil_change_km' => 85000, 'monthly_fuel_allowance' => 25.000, 'insurance_expiry' => '2026-05-10', 'comprehensive_insurance_expiry' => '2026-05-10', 'food_authority_license_expiry' => '2026-05-15', 'next_service_due' => '2026-05-01'],
            ['plate_number' => '44101-KW', 'make' => 'Kia', 'model' => 'Picanto', 'year' => 2022, 'color' => 'White', 'vin' => 'KNADN512BL0000008', 'status' => 'available', 'odometer_km' => 72000, 'last_oil_change_km' => 72000, 'monthly_fuel_allowance' => 25.000, 'insurance_expiry' => '2027-01-01', 'comprehensive_insurance_expiry' => '2027-01-01', 'food_authority_license_expiry' => '2027-01-01', 'next_service_due' => '2026-09-01'],
        ];
        foreach ($vehicles as $v) {
            Vehicle::firstOrCreate(['plate_number' => $v['plate_number']], $v);
        }
        $this->command->info('✓ 8 vehicles seeded (4 working, 2 available, 1 maintenance, 1 idle)');

        // ── Vehicle Assignments (4 active drivers → 4 working vehicles) ──
        $emp1 = Employee::where('employee_number', 'EMP-001')->first();
        $emp2 = Employee::where('employee_number', 'EMP-002')->first();
        $emp3 = Employee::where('employee_number', 'EMP-003')->first();
        $emp4 = Employee::where('employee_number', 'EMP-004')->first();
        $v1 = Vehicle::where('plate_number', '11234-KW')->first();
        $v2 = Vehicle::where('plate_number', '11235-KW')->first();
        $v3 = Vehicle::where('plate_number', '22001-KW')->first();
        $v4 = Vehicle::where('plate_number', '22002-KW')->first();
        $c1 = Contract::where('contract_number', 'YG-2026-Q1')->first();
        $c2 = Contract::where('contract_number', 'KT-2026-H1')->first();
        $c3 = Contract::where('contract_number', 'TB-2026-FX')->first();

        $assignments = [
            ['vehicle_id' => $v1->id, 'employee_id' => $emp1->id, 'contract_id' => $c1->id, 'assigned_date' => '2026-01-05', 'is_active' => true],
            ['vehicle_id' => $v2->id, 'employee_id' => $emp2->id, 'contract_id' => $c2->id, 'assigned_date' => '2026-01-10', 'is_active' => true],
            ['vehicle_id' => $v3->id, 'employee_id' => $emp3->id, 'contract_id' => $c1->id, 'assigned_date' => '2026-01-15', 'is_active' => true],
            ['vehicle_id' => $v4->id, 'employee_id' => $emp4->id, 'contract_id' => $c3->id, 'assigned_date' => '2026-02-01', 'is_active' => true],
        ];
        foreach ($assignments as $a) {
            VehicleAssignment::firstOrCreate(
                ['vehicle_id' => $a['vehicle_id'], 'is_active' => true],
                $a
            );
        }
        $this->command->info('✓ 4 active vehicle assignments seeded');

        // ── Daily Logs (last 14 days for 4 active drivers) ──
        $operator = User::where('email', 'op1@fleetops.com')->first();
        $driverVehicleContract = [
            [$emp1, $v1, $c1, 1.350],
            [$emp2, $v2, $c2, 1.000],
            [$emp3, $v3, $c1, 1.350],
            [$emp4, $v4, $c3, 0],
        ];

        $logCount = 0;
        for ($day = 13; $day >= 0; $day--) {
            $date = now()->subDays($day)->toDateString();
            foreach ($driverVehicleContract as [$emp, $veh, $con, $rate]) {
                if (rand(0, 10) < 2) continue; // skip ~20% days randomly

                $orders = rand(15, 45);
                $cashOrders = (int)($orders * rand(30, 60) / 100);
                $onlineOrders = $orders - $cashOrders;
                $cashCollected = round($cashOrders * rand(2, 5) + rand(0, 999) / 1000, 3);
                $settled = ($day > 3) ? $cashCollected : round($cashCollected * rand(40, 80) / 100, 3);
                $pending = round($cashCollected - $settled, 3);
                $income = round($orders * $rate, 3);
                $odoStart = $veh->odometer_km - ($day * rand(80, 150));
                $odoEnd = $odoStart + rand(80, 150);

                $existing = DailyLog::where('employee_id', $emp->id)
                    ->where('vehicle_id', $veh->id)
                    ->where('log_date', $date)
                    ->first();
                if ($existing) continue;

                DailyLog::create([
                    'employee_id' => $emp->id,
                    'vehicle_id' => $veh->id,
                    'contract_id' => $con->id,
                    'created_by' => $operator->id,
                    'log_date' => $date,
                    'orders_count' => $orders,
                    'orders_online' => $onlineOrders,
                    'orders_cash' => $cashOrders,
                    'cash_collected' => $cashCollected,
                    'cash_settled' => $settled,
                    'cash_pending' => max(0, $pending),
                    'rate_per_order' => $rate,
                    'income_amount' => $income,
                    'odometer_start' => max(0, $odoStart),
                    'odometer_end' => max(0, $odoEnd),
                ]);
                $logCount++;
            }
        }
        $this->command->info("✓ {$logCount} daily logs seeded (last 14 days)");
    }
}
