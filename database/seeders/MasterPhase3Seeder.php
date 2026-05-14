<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Company;
use App\Models\User;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\Violation;
use App\Models\MaintenanceRecord;
use App\Models\SalaryAdvance;

/**
 * Phase 3: APRIL 2026 — Previous month data (Eagle only)
 * 
 * Completely independent from May. Tests historical month viewing.
 * 3 employees, 2 vehicles, 2 contracts, 700 total orders.
 */
class MasterPhase3Seeder extends Seeder
{
    public function run(): void
    {
        $eagle = Company::where('code', 'eagle')->first();
        $admin = User::where('email', 'mersal@fleetops.kw')->first();
        app()->instance('current_company_id', $eagle->id);

        $ahmed  = Employee::where('employee_number', 'EG-001')->where('company_id', $eagle->id)->first();
        $omar   = Employee::where('employee_number', 'EG-002')->where('company_id', $eagle->id)->first();
        $raju   = Employee::where('employee_number', 'EG-003')->where('company_id', $eagle->id)->first();

        $vKW7777 = Vehicle::where('plate_number', 'KW-7777')->where('company_id', $eagle->id)->first();
        $vKW8888 = Vehicle::where('plate_number', 'KW-8888')->where('company_id', $eagle->id)->first();

        $ctTalabat = Contract::where('contract_number', 'TB-Q2-2026')->where('company_id', $eagle->id)->first();
        $ctKaram   = Contract::where('contract_number', 'KR-MAY-2026')->where('company_id', $eagle->id)->first();

        $this->command->info('');
        $this->command->info('── Phase 3: April 2026 (Eagle) ──');

        // ═══════════════════════════════════════════════════
        // DAILY LOGS — April 2026
        // Ahmed: 500 orders on Talabat, KW-7777
        // Raju:  200 orders on Talabat, KW-7777
        // Omar:  90 orders on Karam, KW-8888
        // ═══════════════════════════════════════════════════

        // Spread orders across April days for realism
        $aprilDays = 25; // working days
        $ahmedPerDay = 500 / $aprilDays; // ~20/day
        $rajuPerDay  = 200 / $aprilDays; // ~8/day
        $omarPerDay  = 90 / $aprilDays;  // ~3.6/day

        $ahmedRemaining = 500;
        $rajuRemaining  = 200;
        $omarRemaining  = 90;
        $ahmedCashTotal = 120.000;
        $omarCashTotal  = 40.000;

        $dayIndex = 0;
        for ($d = 1; $d <= 30; $d++) {
            $date = sprintf('2026-04-%02d', $d);
            $dow = date('N', strtotime($date));
            if ($dow == 5) continue; // Skip Fridays

            $dayIndex++;

            // Ahmed
            $ahmedToday = ($dayIndex == $aprilDays) ? $ahmedRemaining : (int) round($ahmedPerDay);
            $ahmedToday = min($ahmedToday, $ahmedRemaining);
            if ($ahmedToday > 0) {
                $ahmedCash = ($dayIndex <= 5) ? round($ahmedCashTotal / 5, 3) : 0;
                DailyLog::firstOrCreate(
                    ['employee_id' => $ahmed->id, 'log_date' => $date, 'company_id' => $eagle->id],
                    [
                        'employee_id' => $ahmed->id, 'vehicle_id' => $vKW7777->id,
                        'contract_id' => $ctTalabat->id, 'created_by' => $admin->id,
                        'log_date' => $date, 'orders_count' => $ahmedToday,
                        'orders_online' => (int) round($ahmedToday * 0.8),
                        'orders_cash' => $ahmedToday - (int) round($ahmedToday * 0.8),
                        'cash_collected' => $ahmedCash, 'cash_settled' => $ahmedCash, 'cash_pending' => 0,
                        'rate_per_order' => 0.900, 'income_amount' => round($ahmedToday * 0.900, 3),
                        'company_id' => $eagle->id,
                    ]
                );
                $ahmedRemaining -= $ahmedToday;
            }

            // Raju
            $rajuToday = ($dayIndex == $aprilDays) ? $rajuRemaining : (int) round($rajuPerDay);
            $rajuToday = min($rajuToday, $rajuRemaining);
            if ($rajuToday > 0) {
                DailyLog::firstOrCreate(
                    ['employee_id' => $raju->id, 'log_date' => $date, 'company_id' => $eagle->id],
                    [
                        'employee_id' => $raju->id, 'vehicle_id' => $vKW7777->id,
                        'contract_id' => $ctTalabat->id, 'created_by' => $admin->id,
                        'log_date' => $date, 'orders_count' => $rajuToday,
                        'orders_online' => $rajuToday, 'orders_cash' => 0,
                        'cash_collected' => 0, 'cash_settled' => 0, 'cash_pending' => 0,
                        'rate_per_order' => 0.900, 'income_amount' => round($rajuToday * 0.900, 3),
                        'company_id' => $eagle->id,
                    ]
                );
                $rajuRemaining -= $rajuToday;
            }

            // Omar
            $omarToday = ($dayIndex == $aprilDays) ? $omarRemaining : max(3, (int) round($omarPerDay));
            $omarToday = min($omarToday, $omarRemaining);
            if ($omarToday > 0) {
                $omarCash = ($dayIndex <= 5) ? round($omarCashTotal / 5, 3) : 0;
                DailyLog::firstOrCreate(
                    ['employee_id' => $omar->id, 'log_date' => $date, 'company_id' => $eagle->id],
                    [
                        'employee_id' => $omar->id, 'vehicle_id' => $vKW8888->id,
                        'contract_id' => $ctKaram->id, 'created_by' => $admin->id,
                        'log_date' => $date, 'orders_count' => $omarToday,
                        'orders_online' => (int) round($omarToday * 0.7),
                        'orders_cash' => $omarToday - (int) round($omarToday * 0.7),
                        'cash_collected' => $omarCash, 'cash_settled' => $omarCash, 'cash_pending' => 0,
                        'rate_per_order' => 0, 'income_amount' => 0,
                        'company_id' => $eagle->id,
                    ]
                );
                $omarRemaining -= $omarToday;
            }
        }

        $this->command->info('✓ April daily logs: Ahmed=500, Raju=200, Omar=90');

        // ═══ April Violation ═══
        Violation::firstOrCreate(['reference_number' => 'APR-VIO-001', 'company_id' => $eagle->id], [
            'employee_id' => $ahmed->id, 'vehicle_id' => $vKW7777->id,
            'created_by' => $admin->id, 'violation_date' => '2026-04-12',
            'violation_type' => 'تجاوز سرعة', 'reference_number' => 'APR-VIO-001',
            'amount' => 10.000, 'is_driver_liable' => true, 'is_deducted' => false,
            'company_id' => $eagle->id,
        ]);
        $this->command->info('✓ April: 1 violation (Ahmed 10 KD)');

        // ═══ April Maintenance ═══
        MaintenanceRecord::firstOrCreate(
            ['vehicle_id' => $vKW8888->id, 'maintenance_date' => '2026-04-20', 'company_id' => $eagle->id],
            [
                'vehicle_id' => $vKW8888->id, 'reported_by' => $admin->id,
                'approved_by' => $admin->id, 'approved_at' => '2026-04-21',
                'garage_name' => 'ورشة الخليج', 'maintenance_type' => 'periodic',
                'maintenance_date' => '2026-04-20',
                'estimated_cost' => 25.000, 'actual_cost' => 25.000,
                'status' => 'approved', 'is_driver_liable' => false,
                'company_id' => $eagle->id,
            ]
        );
        $this->command->info('✓ April: 1 maintenance (KW-8888 oil change 25 KD, company pays)');

        // ═══ Ahmed's Salary Advance (created before April, 1 installment paid pre-April) ═══
        SalaryAdvance::firstOrCreate(
            ['employee_id' => $ahmed->id, 'advance_date' => '2026-03-01', 'company_id' => $eagle->id],
            [
                'employee_id' => $ahmed->id, 'approved_by' => $admin->id,
                'amount' => 200.000, 'monthly_installment' => 50.000,
                'total_installments' => 4, 'paid_installments' => 1,
                'remaining_balance' => 150.000,
                'advance_date' => '2026-03-01', 'reason' => 'ظروف عائلية',
                'status' => 'active', 'company_id' => $eagle->id,
            ]
        );
        $this->command->info('✓ Ahmed advance: 200 KD, 1 paid pre-April, remaining=150');
        $this->command->info('  → April will deduct 50 → remaining=100');
        $this->command->info('  → May will deduct 50 → remaining=50');
    }
}
