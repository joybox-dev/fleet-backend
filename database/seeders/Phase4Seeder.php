<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Employee;
use App\Models\CustodyItem;
use App\Models\PayrollRun;
use App\Models\PayrollSlip;
use App\Models\DailyLog;
use App\Models\Violation;

class Phase4Seeder extends Seeder
{
    public function run(): void
    {
        $operator = User::where('email', 'op1@fleetops.com')->first();
        $admin = User::where('email', 'admin@fleetops.com')->first();

        $emp1 = Employee::where('employee_number', 'EMP-001')->first();
        $emp2 = Employee::where('employee_number', 'EMP-002')->first();
        $emp3 = Employee::where('employee_number', 'EMP-003')->first();
        $emp4 = Employee::where('employee_number', 'EMP-004')->first();

        // ── Custody Items ──
        $items = [
            ['employee_id' => $emp1->id, 'issued_by' => $operator->id, 'item_type' => 'phone', 'item_description' => 'iPhone 13 - Black', 'serial_number' => 'IMEI-001122334455', 'value' => 75.000, 'issued_date' => '2025-06-05', 'is_returned' => false],
            ['employee_id' => $emp1->id, 'issued_by' => $operator->id, 'item_type' => 'sim', 'item_description' => 'Zain SIM - Data Plan', 'serial_number' => 'SIM-99001122', 'value' => 5.000, 'issued_date' => '2025-06-05', 'is_returned' => false],
            ['employee_id' => $emp1->id, 'issued_by' => $operator->id, 'item_type' => 'clothing', 'item_description' => 'زي موحد - مقاس L', 'value' => 15.000, 'issued_date' => '2025-06-05', 'is_returned' => false],
            ['employee_id' => $emp2->id, 'issued_by' => $operator->id, 'item_type' => 'phone', 'item_description' => 'Samsung A14 - White', 'serial_number' => 'IMEI-556677889900', 'value' => 45.000, 'issued_date' => '2025-08-20', 'is_returned' => false],
            ['employee_id' => $emp2->id, 'issued_by' => $operator->id, 'item_type' => 'sim', 'item_description' => 'Ooredoo SIM', 'serial_number' => 'SIM-88007766', 'value' => 5.000, 'issued_date' => '2025-08-20', 'is_returned' => false],
            ['employee_id' => $emp3->id, 'issued_by' => $operator->id, 'item_type' => 'phone', 'item_description' => 'Samsung A14 - Black', 'serial_number' => 'IMEI-112233445566', 'value' => 45.000, 'issued_date' => '2026-01-15', 'is_returned' => false],
            ['employee_id' => $emp3->id, 'issued_by' => $operator->id, 'item_type' => 'clothing', 'item_description' => 'زي موحد - مقاس M', 'value' => 15.000, 'issued_date' => '2026-01-15', 'is_returned' => false],
            ['employee_id' => $emp4->id, 'issued_by' => $operator->id, 'item_type' => 'phone', 'item_description' => 'iPhone 12 - Blue', 'serial_number' => 'IMEI-998877665544', 'value' => 60.000, 'issued_date' => '2025-03-05', 'is_returned' => false],
            // One returned item (ex-employee scenario)
            ['employee_id' => $emp4->id, 'issued_by' => $operator->id, 'item_type' => 'sim', 'item_description' => 'STC SIM - Old', 'serial_number' => 'SIM-11223344', 'value' => 5.000, 'issued_date' => '2025-03-05', 'returned_date' => '2026-01-15', 'is_returned' => true, 'return_condition' => 'good'],
            // Damaged item
            ['employee_id' => $emp2->id, 'issued_by' => $operator->id, 'item_type' => 'other', 'item_description' => 'حقيبة توصيل حرارية', 'value' => 20.000, 'issued_date' => '2025-09-01', 'returned_date' => '2026-03-10', 'is_returned' => true, 'return_condition' => 'damaged', 'deduction_amount' => 10.000],
        ];
        foreach ($items as $it) {
            CustodyItem::firstOrCreate(
                ['employee_id' => $it['employee_id'], 'item_type' => $it['item_type'], 'issued_date' => $it['issued_date'], 'item_description' => $it['item_description'] ?? ''],
                $it
            );
        }
        $this->command->info('✓ 10 custody items seeded (8 active, 1 returned good, 1 returned damaged)');

        // ── Payroll Run (April 2026 — approved) ──
        $run = PayrollRun::firstOrCreate(
            ['year' => 2026, 'month' => 4],
            [
                'created_by' => $admin->id,
                'year' => 2026,
                'month' => 4,
                'status' => 'approved',
                'approved_at' => now()->subDays(3),
                'approved_by' => $admin->id,
                'total_official' => 0,
                'total_actual' => 0,
                'total_cash_diff' => 0,
            ]
        );

        $activeEmps = [$emp1, $emp2, $emp3, $emp4];
        $totalOfficial = 0;
        $totalActual = 0;

        foreach ($activeEmps as $emp) {
            $monthOrders = DailyLog::where('employee_id', $emp->id)
                ->whereYear('log_date', 2026)->whereMonth('log_date', 4)
                ->sum('orders_count') ?: rand(200, 500);

            $violationsAmt = Violation::where('employee_id', $emp->id)
                ->where('is_driver_liable', true)
                ->where('is_deducted', false)
                ->sum('amount');

            $ordersBonus = round($monthOrders * ($emp->rate_per_order ?: 0), 3);
            $fuelAllow = 35.000;
            $grossOfficial = round($emp->official_salary + $ordersBonus + $fuelAllow - $violationsAmt, 3);
            $grossActual = round($emp->actual_salary + $ordersBonus + $fuelAllow - $violationsAmt, 3);
            $cashPortion = round(max(0, $grossActual - $grossOfficial), 3);

            PayrollSlip::firstOrCreate(
                ['payroll_run_id' => $run->id, 'employee_id' => $emp->id],
                [
                    'payroll_run_id' => $run->id,
                    'employee_id' => $emp->id,
                    'base_official' => $emp->official_salary,
                    'base_actual' => $emp->actual_salary ?: $emp->official_salary,
                    'orders_bonus' => $ordersBonus,
                    'fuel_allowance' => $fuelAllow,
                    'other_bonuses' => 0,
                    'total_orders' => $monthOrders,
                    'violations_deduction' => $violationsAmt,
                    'maintenance_deduction' => 0,
                    'custody_deduction' => 0,
                    'other_deductions' => 0,
                    'gross_official' => max(0, $grossOfficial),
                    'gross_actual' => max(0, $grossActual),
                    'cash_portion' => $cashPortion,
                ]
            );

            $totalOfficial += max(0, $grossOfficial);
            $totalActual += max(0, $grossActual);
        }

        $run->update([
            'total_official' => round($totalOfficial, 3),
            'total_actual' => round($totalActual, 3),
            'total_cash_diff' => round($totalActual - $totalOfficial, 3),
        ]);
        $this->command->info('✓ Payroll run April 2026 seeded with ' . count($activeEmps) . ' slips');
    }
}
