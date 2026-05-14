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
use App\Models\CustodyItem;
use App\Models\SalaryAdvance;
use App\Models\EmployeeLeave;
use App\Models\LeaveType;
use App\Models\DriverGuarantee;
use App\Models\EmployeeEvaluation;
use App\Models\EvaluationCriterion;
use App\Models\EvaluationScore;
use App\Models\VehicleExpense;

/**
 * Phase 4B: MAY 2026 — Al-Buraq Logistics
 * Mohammad=500 on Deliveroo; Salem=350 on Deliveroo; Yousef=80 on Al-Sawan
 */
class MasterPhase4BSeeder extends Seeder
{
    public function run(): void
    {
        $buraq = Company::where('code', 'buraq')->first();
        $admin = User::where('email', 'admin-buraq@fleetops.kw')->first()
              ?? User::where('email', 'mersal@fleetops.kw')->first();
        app()->instance('current_company_id', $buraq->id);

        $yousef   = Employee::where('employee_number', 'BQ-001')->first();
        $mohammad = Employee::where('employee_number', 'BQ-002')->first();
        $salem    = Employee::where('employee_number', 'BQ-003')->first();

        $v12 = Vehicle::where('plate_number', 'KW-1234')->first();
        $v56 = Vehicle::where('plate_number', 'KW-5678')->first();

        $ctDel = Contract::where('contract_number', 'DL-Q2-2026')->first();
        $ctSaw = Contract::where('contract_number', 'SW-MAY-2026')->first();

        $this->command->info('');
        $this->command->info('── Phase 4B: May 2026 (Buraq) ──');

        // ═══ DAILY LOGS ═══
        $targets = [
            [$mohammad->id, $v56->id, $ctDel->id, 500, 1.100, 100, 100],
            [$salem->id,    $v12->id, $ctDel->id, 350, 1.100, 300, 250],
            [$yousef->id,   $v12->id, $ctSaw->id, 80,  0,     60,  60],
        ];

        foreach ($targets as [$empId, $vehId, $conId, $totalOrders, $rate, $cashCol, $cashSet]) {
            $remaining = $totalOrders;
            $cashRemaining = $cashCol;
            $cashSettledRemaining = $cashSet;
            $workDays = 25;
            $perDay = (int) round($totalOrders / $workDays);
            $dayIndex = 0;

            for ($d = 1; $d <= 31; $d++) {
                $date = sprintf('2026-05-%02d', $d);
                if (!checkdate(5, $d, 2026)) continue;
                if (date('N', strtotime($date)) == 5) continue;
                if ($remaining <= 0) break;

                $dayIndex++;
                $today = ($dayIndex >= $workDays) ? $remaining : min($perDay, $remaining);
                $todayCash = 0;
                $todaySettled = 0;

                if ($cashRemaining > 0 && $dayIndex <= 8) {
                    $todayCash = min(round($cashCol / 8, 3), $cashRemaining);
                    $cashRemaining -= $todayCash;
                    $todaySettled = min(round($cashSet / 8, 3), $cashSettledRemaining);
                    $cashSettledRemaining -= $todaySettled;
                }

                DailyLog::firstOrCreate(
                    ['employee_id' => $empId, 'log_date' => $date, 'company_id' => $buraq->id],
                    [
                        'employee_id' => $empId, 'vehicle_id' => $vehId,
                        'contract_id' => $conId, 'created_by' => $admin->id,
                        'log_date' => $date, 'orders_count' => $today,
                        'orders_online' => (int) round($today * 0.8),
                        'orders_cash' => $today - (int) round($today * 0.8),
                        'cash_collected' => $todayCash, 'cash_settled' => $todaySettled,
                        'cash_pending' => max(0, round($todayCash - $todaySettled, 3)),
                        'rate_per_order' => $rate,
                        'income_amount' => round($today * $rate, 3),
                        'company_id' => $buraq->id,
                    ]
                );
                $remaining -= $today;
            }
        }
        $this->command->info('✓ May logs: Mohammad=500, Salem=350, Yousef=80');

        // ═══ VIOLATIONS ═══
        Violation::firstOrCreate(['reference_number' => 'MAY-VIO-B01', 'company_id' => $buraq->id], [
            'employee_id' => $mohammad->id, 'vehicle_id' => $v56->id,
            'created_by' => $admin->id, 'violation_date' => '2026-05-10',
            'violation_type' => 'تجاوز سرعة', 'reference_number' => 'MAY-VIO-B01',
            'amount' => 25.000, 'is_driver_liable' => true, 'is_deducted' => false,
            'company_id' => $buraq->id,
        ]);
        Violation::firstOrCreate(['reference_number' => 'MAY-VIO-B02', 'company_id' => $buraq->id], [
            'employee_id' => $yousef->id, 'vehicle_id' => $v12->id,
            'created_by' => $admin->id, 'violation_date' => '2026-05-22',
            'violation_type' => 'وقوف خاطئ', 'reference_number' => 'MAY-VIO-B02',
            'amount' => 10.000, 'is_driver_liable' => false, 'is_deducted' => false,
            'company_id' => $buraq->id,
        ]);
        $this->command->info('✓ Violations: Mohammad=25(driver), Yousef=10(company)');

        // ═══ MAINTENANCE ═══
        MaintenanceRecord::firstOrCreate(
            ['vehicle_id' => $v56->id, 'maintenance_date' => '2026-05-14', 'company_id' => $buraq->id],
            [
                'reported_by' => $admin->id, 'approved_by' => $admin->id,
                'approved_at' => '2026-05-15', 'garage_name' => 'مركز الإطارات',
                'maintenance_type' => 'repair', 'maintenance_date' => '2026-05-14',
                'estimated_cost' => 120.000, 'actual_cost' => 120.000,
                'status' => 'approved', 'is_driver_liable' => true,
                'liable_employee_id' => $mohammad->id, 'driver_deduction' => 20.000,
                'company_id' => $buraq->id,
            ]
        );
        MaintenanceRecord::firstOrCreate(
            ['vehicle_id' => $v12->id, 'maintenance_date' => '2026-05-20', 'company_id' => $buraq->id],
            [
                'reported_by' => $admin->id, 'approved_by' => $admin->id,
                'approved_at' => '2026-05-21', 'garage_name' => 'وكالة تويوتا',
                'maintenance_type' => 'periodic', 'maintenance_date' => '2026-05-20',
                'estimated_cost' => 60.000, 'actual_cost' => 60.000,
                'status' => 'approved', 'is_driver_liable' => false,
                'company_id' => $buraq->id,
            ]
        );
        $this->command->info('✓ Maintenance: KW-5678(120,driver=20), KW-1234(60,company)');

        // ═══ CUSTODY ═══
        CustodyItem::firstOrCreate(
            ['employee_id' => $yousef->id, 'item_description' => 'تابلت Samsung', 'company_id' => $buraq->id],
            [
                'issued_by' => $admin->id, 'item_type' => 'other',
                'item_description' => 'تابلت Samsung', 'value' => 50.000,
                'issued_date' => '2025-01-15', 'status' => 'active',
                'company_id' => $buraq->id,
            ]
        );
        CustodyItem::firstOrCreate(
            ['employee_id' => $salem->id, 'item_description' => 'حقيبة تبريد', 'company_id' => $buraq->id],
            [
                'issued_by' => $admin->id, 'item_type' => 'other',
                'item_description' => 'حقيبة تبريد', 'value' => 35.000,
                'issued_date' => '2025-04-10', 'status' => 'returned',
                'returned_date' => '2026-05-08', 'return_condition' => 'lost',
                'deduction_amount' => 25.000, 'company_id' => $buraq->id,
            ]
        );
        $this->command->info('✓ Custody: Yousef=active(tablet), Salem=lost(25KD)');

        // ═══ ADVANCE ═══
        SalaryAdvance::firstOrCreate(
            ['employee_id' => $mohammad->id, 'advance_date' => '2026-05-01', 'company_id' => $buraq->id],
            [
                'approved_by' => $admin->id, 'amount' => 150.000,
                'monthly_installment' => 30.000, 'total_installments' => 5,
                'paid_installments' => 0, 'remaining_balance' => 150.000,
                'advance_date' => '2026-05-01', 'reason' => 'مصاريف طبية',
                'status' => 'active', 'company_id' => $buraq->id,
            ]
        );
        $this->command->info('✓ Mohammad advance: 150 KD (new)');

        // ═══ LEAVES ═══
        $unpaid = LeaveType::where('name', 'Unpaid Leave')->where('company_id', $buraq->id)->first();
        $annual = LeaveType::where('name', 'Annual Leave')->where('company_id', $buraq->id)->first();

        EmployeeLeave::firstOrCreate(
            ['employee_id' => $yousef->id, 'start_date' => '2026-05-12', 'company_id' => $buraq->id],
            [
                'leave_type_id' => $unpaid->id,
                'start_date' => '2026-05-12', 'end_date' => '2026-05-14',
                'days_count' => 3, 'status' => 'approved',
                'is_paid' => false, 'daily_rate' => 8.333,
                'penalty_multiplier' => 1.0, 'formula_version' => 'v1_actual_div_30',
                'total_deduction' => 25.000,
                'approved_by' => $admin->id, 'approved_at' => '2026-05-11',
                'reason' => 'سفر عائلي', 'company_id' => $buraq->id,
            ]
        );
        EmployeeLeave::firstOrCreate(
            ['employee_id' => $salem->id, 'start_date' => '2026-05-26', 'company_id' => $buraq->id],
            [
                'leave_type_id' => $annual->id,
                'start_date' => '2026-05-26', 'end_date' => '2026-05-27',
                'days_count' => 2, 'status' => 'approved',
                'is_paid' => true, 'daily_rate' => 5.333,
                'penalty_multiplier' => 1.0, 'formula_version' => 'v1_actual_div_30',
                'total_deduction' => 0,
                'approved_by' => $admin->id, 'approved_at' => '2026-05-25',
                'reason' => 'عطلة', 'company_id' => $buraq->id,
            ]
        );
        $this->command->info('✓ Leaves: Yousef=3d unpaid(25), Salem=2d annual(0)');

        // ═══ GUARANTEES ═══
        DriverGuarantee::firstOrCreate(
            ['employee_id' => $mohammad->id, 'guarantee_type' => 'passport', 'company_id' => $buraq->id],
            ['document_number' => 'P-IRN-987654', 'received_date' => '2025-06-01', 'status' => 'held', 'company_id' => $buraq->id]
        );
        DriverGuarantee::firstOrCreate(
            ['employee_id' => $mohammad->id, 'guarantee_type' => 'driving_license', 'company_id' => $buraq->id],
            ['document_number' => 'DL-IRN-555111', 'received_date' => '2025-06-01', 'status' => 'held', 'company_id' => $buraq->id]
        );
        $this->command->info('✓ Guarantees: Mohammad=passport+license');

        // ═══ EVALUATIONS ═══
        $criteria = EvaluationCriterion::where('company_id', $buraq->id)->get()->keyBy('name');
        $evalData = [
            [$yousef->id,   [90, 95, 85], 90.0],
            [$mohammad->id, [70, 65, 75], 70.0],
            [$salem->id,    [82, 78, 80], 80.2],
        ];
        foreach ($evalData as [$empId, $scores, $overall]) {
            $eval = EmployeeEvaluation::firstOrCreate(
                ['employee_id' => $empId, 'evaluation_date' => '2026-05-30', 'company_id' => $buraq->id],
                [
                    'evaluator_id' => $admin->id, 'evaluation_date' => '2026-05-30',
                    'period_from' => '2026-05-01', 'period_to' => '2026-05-31',
                    'overall_score' => $overall, 'status' => 'completed',
                    'company_id' => $buraq->id,
                ]
            );
            $i = 0;
            foreach (['Work Performance', 'Punctuality', 'Customer Service'] as $cName) {
                if (isset($criteria[$cName])) {
                    EvaluationScore::firstOrCreate(
                        ['evaluation_id' => $eval->id, 'criterion_id' => $criteria[$cName]->id],
                        ['score' => $scores[$i]]
                    );
                }
                $i++;
            }
        }
        $this->command->info('✓ Evaluations: 3 employees scored');

        // ═══ VEHICLE EXPENSES ═══
        $expenses = [
            [$v12->id, 'fuel',    60.000, '2026-05-08', 'تعبئة وقود'],
            [$v12->id, 'service', 40.000, '2026-05-20', 'صيانة دورية'],
            [$v56->id, 'tires',   70.000, '2026-05-14', 'إطارات'],
        ];
        foreach ($expenses as [$vId, $type, $amt, $date, $desc]) {
            VehicleExpense::firstOrCreate(
                ['vehicle_id' => $vId, 'expense_date' => $date, 'expense_type' => $type, 'company_id' => $buraq->id],
                [
                    'vehicle_id' => $vId, 'expense_type' => $type, 'amount' => $amt,
                    'expense_date' => $date, 'description' => $desc, 'company_id' => $buraq->id,
                ]
            );
        }
        $this->command->info('✓ Vehicle expenses: KW-1234=100, KW-5678=70');
    }
}
