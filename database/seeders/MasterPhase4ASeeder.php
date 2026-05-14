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
 * Phase 4A: MAY 2026 — Eagle Delivery
 * Ahmed=600, Raju=300 on Talabat; Omar=100 on Karam; Khaled=400 on Carriage
 */
class MasterPhase4ASeeder extends Seeder
{
    public function run(): void
    {
        $eagle = Company::where('code', 'eagle')->first();
        $admin = User::where('email', 'mersal@fleetops.kw')->first();
        app()->instance('current_company_id', $eagle->id);

        $ahmed  = Employee::where('employee_number', 'EG-001')->where('company_id', $eagle->id)->first();
        $omar   = Employee::where('employee_number', 'EG-002')->where('company_id', $eagle->id)->first();
        $raju   = Employee::where('employee_number', 'EG-003')->where('company_id', $eagle->id)->first();
        $khaled = Employee::where('employee_number', 'EG-004')->where('company_id', $eagle->id)->first();

        $v7 = Vehicle::where('plate_number', 'KW-7777')->where('company_id', $eagle->id)->first();
        $v8 = Vehicle::where('plate_number', 'KW-8888')->where('company_id', $eagle->id)->first();
        $v9 = Vehicle::where('plate_number', 'KW-9999')->where('company_id', $eagle->id)->first();

        $ctT = Contract::where('contract_number', 'TB-Q2-2026')->first();
        $ctK = Contract::where('contract_number', 'KR-MAY-2026')->first();
        $ctC = Contract::where('contract_number', 'CR-MAY-2026')->first();

        $this->command->info('');
        $this->command->info('── Phase 4A: May 2026 (Eagle) ──');

        // ═══ DAILY LOGS ═══
        $targets = [
            [$ahmed->id,  $v7->id, $ctT->id, 600, 0.900, 180, 150], // [emp, veh, contract, orders, rate, cashCollected, cashSettled]
            [$raju->id,   $v7->id, $ctT->id, 300, 0.900, 0, 0],
            [$omar->id,   $v8->id, $ctK->id, 100, 0,     50, 50],   // fixed contract: rate=0, income=0
            [$khaled->id, $v9->id, $ctC->id, 400, 0.400, 120, 100], // hybrid: rate=0.400 for income_amount
        ];

        foreach ($targets as [$empId, $vehId, $conId, $totalOrders, $rate, $cashCol, $cashSet]) {
            $remaining = $totalOrders;
            $cashRemaining = $cashCol;
            $cashSettledRemaining = $cashSet;
            $dayIndex = 0;
            $workDays = 25;
            $perDay = (int) round($totalOrders / $workDays);

            for ($d = 1; $d <= 31; $d++) {
                $date = sprintf('2026-05-%02d', $d);
                if (!checkdate(5, $d, 2026)) continue;
                $dow = date('N', strtotime($date));
                if ($dow == 5) continue;
                if ($remaining <= 0) break;

                $dayIndex++;
                $today = ($dayIndex >= $workDays) ? $remaining : min($perDay, $remaining);
                $todayCash = 0;
                $todaySettled = 0;

                if ($cashRemaining > 0 && $dayIndex <= 8) {
                    $todayCash = round($cashCol / 8, 3);
                    $todayCash = min($todayCash, $cashRemaining);
                    $cashRemaining -= $todayCash;

                    $todaySettled = round($cashSet / 8, 3);
                    $todaySettled = min($todaySettled, $cashSettledRemaining);
                    $cashSettledRemaining -= $todaySettled;
                }

                DailyLog::firstOrCreate(
                    ['employee_id' => $empId, 'log_date' => $date, 'company_id' => $eagle->id],
                    [
                        'employee_id' => $empId, 'vehicle_id' => $vehId,
                        'contract_id' => $conId, 'created_by' => $admin->id,
                        'log_date' => $date, 'orders_count' => $today,
                        'orders_online' => (int) round($today * 0.8),
                        'orders_cash' => $today - (int) round($today * 0.8),
                        'cash_collected' => $todayCash,
                        'cash_settled' => $todaySettled,
                        'cash_pending' => max(0, round($todayCash - $todaySettled, 3)),
                        'rate_per_order' => $rate,
                        'income_amount' => round($today * $rate, 3),
                        'company_id' => $eagle->id,
                    ]
                );
                $remaining -= $today;
            }
        }
        $this->command->info('✓ May logs: Ahmed=600, Raju=300, Omar=100, Khaled=400');

        // ═══ VIOLATIONS ═══
        Violation::firstOrCreate(['reference_number' => 'MAY-VIO-E01', 'company_id' => $eagle->id], [
            'employee_id' => $ahmed->id, 'vehicle_id' => $v7->id,
            'created_by' => $admin->id, 'violation_date' => '2026-05-08',
            'violation_type' => 'تجاوز إشارة', 'reference_number' => 'MAY-VIO-E01',
            'amount' => 20.000, 'is_driver_liable' => true, 'is_deducted' => false,
            'company_id' => $eagle->id,
        ]);
        Violation::firstOrCreate(['reference_number' => 'MAY-VIO-E02', 'company_id' => $eagle->id], [
            'employee_id' => $khaled->id, 'vehicle_id' => $v9->id,
            'created_by' => $admin->id, 'violation_date' => '2026-05-15',
            'violation_type' => 'وقوف خاطئ', 'reference_number' => 'MAY-VIO-E02',
            'amount' => 15.000, 'is_driver_liable' => true, 'is_deducted' => false,
            'company_id' => $eagle->id,
        ]);
        $this->command->info('✓ May violations: Ahmed=20, Khaled=15');

        // ═══ MAINTENANCE ═══
        MaintenanceRecord::firstOrCreate(
            ['vehicle_id' => $v7->id, 'maintenance_date' => '2026-05-10', 'company_id' => $eagle->id],
            [
                'reported_by' => $admin->id, 'approved_by' => $admin->id,
                'approved_at' => '2026-05-11', 'garage_name' => 'ورشة النور',
                'maintenance_type' => 'repair', 'maintenance_date' => '2026-05-10',
                'estimated_cost' => 50.000, 'actual_cost' => 50.000,
                'status' => 'approved', 'is_driver_liable' => true,
                'liable_employee_id' => $ahmed->id, 'driver_deduction' => 10.000,
                'company_id' => $eagle->id,
            ]
        );
        MaintenanceRecord::firstOrCreate(
            ['vehicle_id' => $v8->id, 'maintenance_date' => '2026-05-18', 'company_id' => $eagle->id],
            [
                'reported_by' => $admin->id, 'approved_by' => $admin->id,
                'approved_at' => '2026-05-19', 'garage_name' => 'مركز الخليج',
                'maintenance_type' => 'repair', 'maintenance_date' => '2026-05-18',
                'estimated_cost' => 80.000, 'actual_cost' => 80.000,
                'status' => 'approved', 'is_driver_liable' => false,
                'company_id' => $eagle->id,
            ]
        );
        $this->command->info('✓ May maintenance: KW-7777(50,driver=10), KW-8888(80,company)');

        // ═══ CUSTODY ═══
        CustodyItem::firstOrCreate(
            ['employee_id' => $ahmed->id, 'item_description' => 'هاتف عمل iPhone', 'company_id' => $eagle->id],
            [
                'issued_by' => $admin->id, 'item_type' => 'phone',
                'item_description' => 'هاتف عمل iPhone', 'value' => 75.000,
                'issued_date' => '2025-06-05', 'status' => 'returned',
                'returned_date' => '2026-05-05', 'return_condition' => 'damaged',
                'deduction_amount' => 30.000, 'company_id' => $eagle->id,
            ]
        );
        CustodyItem::firstOrCreate(
            ['employee_id' => $raju->id, 'item_description' => 'زي موحد', 'company_id' => $eagle->id],
            [
                'issued_by' => $admin->id, 'item_type' => 'clothing',
                'item_description' => 'زي موحد', 'value' => 14.000,
                'issued_date' => '2025-08-05', 'status' => 'returned',
                'returned_date' => '2026-05-01', 'return_condition' => 'good',
                'deduction_amount' => 0, 'company_id' => $eagle->id,
            ]
        );
        CustodyItem::firstOrCreate(
            ['employee_id' => $khaled->id, 'item_description' => 'حقيبة توصيل', 'company_id' => $eagle->id],
            [
                'issued_by' => $admin->id, 'item_type' => 'bag',
                'item_description' => 'حقيبة توصيل', 'value' => 20.000,
                'issued_date' => '2026-05-01', 'status' => 'active',
                'company_id' => $eagle->id,
            ]
        );
        $this->command->info('✓ Custody: Ahmed=damaged(30), Raju=returned(0), Khaled=active');

        // ═══ OMAR'S ADVANCE (created in May) ═══
        SalaryAdvance::firstOrCreate(
            ['employee_id' => $omar->id, 'advance_date' => '2026-05-01', 'company_id' => $eagle->id],
            [
                'approved_by' => $admin->id, 'amount' => 300.000,
                'monthly_installment' => 75.000, 'total_installments' => 4,
                'paid_installments' => 0, 'remaining_balance' => 300.000,
                'advance_date' => '2026-05-01', 'reason' => 'تأثيث شقة',
                'status' => 'active', 'company_id' => $eagle->id,
            ]
        );
        $this->command->info('✓ Omar advance: 300 KD (new in May)');

        // ═══ LEAVES ═══
        $unpaid = LeaveType::where('name', 'Unpaid Leave')->where('company_id', $eagle->id)->first();
        $annual = LeaveType::where('name', 'Annual Leave')->where('company_id', $eagle->id)->first();

        EmployeeLeave::firstOrCreate(
            ['employee_id' => $ahmed->id, 'start_date' => '2026-05-20', 'company_id' => $eagle->id],
            [
                'leave_type_id' => $unpaid->id,
                'start_date' => '2026-05-20', 'end_date' => '2026-05-21',
                'days_count' => 2, 'status' => 'approved',
                'is_paid' => false, 'daily_rate' => 5.000,
                'penalty_multiplier' => 1.0, 'formula_version' => 'v1_actual_div_30',
                'total_deduction' => 10.000,
                'approved_by' => $admin->id, 'approved_at' => '2026-05-19',
                'reason' => 'ظروف شخصية', 'company_id' => $eagle->id,
            ]
        );
        EmployeeLeave::firstOrCreate(
            ['employee_id' => $khaled->id, 'start_date' => '2026-05-25', 'company_id' => $eagle->id],
            [
                'leave_type_id' => $annual->id,
                'start_date' => '2026-05-25', 'end_date' => '2026-05-25',
                'days_count' => 1, 'status' => 'approved',
                'is_paid' => true, 'daily_rate' => 6.000,
                'penalty_multiplier' => 1.0, 'formula_version' => 'v1_actual_div_30',
                'total_deduction' => 0,
                'approved_by' => $admin->id, 'approved_at' => '2026-05-24',
                'reason' => 'مراجعة طبية', 'company_id' => $eagle->id,
            ]
        );
        $this->command->info('✓ Leaves: Ahmed=2d unpaid(10KD), Khaled=1d annual(0)');

        // ═══ GUARANTEES ═══
        DriverGuarantee::firstOrCreate(
            ['employee_id' => $raju->id, 'guarantee_type' => 'passport', 'company_id' => $eagle->id],
            [
                'document_number' => 'P-IND-123456', 'received_date' => '2025-08-01',
                'status' => 'held', 'company_id' => $eagle->id,
            ]
        );
        DriverGuarantee::firstOrCreate(
            ['employee_id' => $ahmed->id, 'guarantee_type' => 'civil_id', 'company_id' => $eagle->id],
            [
                'document_number' => '280010001001', 'received_date' => '2025-06-01',
                'status' => 'held', 'company_id' => $eagle->id,
            ]
        );
        $this->command->info('✓ Guarantees: Raju=passport, Ahmed=civil_id');

        // ═══ EVALUATIONS ═══
        $criteria = EvaluationCriterion::where('company_id', $eagle->id)->get()->keyBy('name');
        $evalData = [
            [$ahmed->id,  [80, 70, 85], 78.5],
            [$omar->id,   [95, 90, 88], 91.4],
            [$raju->id,   [75, 85, 70], 76.5],
            [$khaled->id, [88, 92, 90], 89.8],
        ];
        foreach ($evalData as [$empId, $scores, $overall]) {
            $eval = EmployeeEvaluation::firstOrCreate(
                ['employee_id' => $empId, 'evaluation_date' => '2026-05-30', 'company_id' => $eagle->id],
                [
                    'evaluator_id' => $admin->id, 'evaluation_date' => '2026-05-30',
                    'period_from' => '2026-05-01', 'period_to' => '2026-05-31',
                    'overall_score' => $overall, 'status' => 'completed',
                    'company_id' => $eagle->id,
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
        $this->command->info('✓ Evaluations: 4 employees scored');

        // ═══ VEHICLE EXPENSES ═══
        $expenses = [
            [$v7->id, 'oil_change', 15.000, '2026-05-12', 'تغيير زيت'],
            [$v7->id, 'tires',      80.000, '2026-05-20', 'إطارات جديدة'],
            [$v8->id, 'wash',        5.000, '2026-05-15', 'غسيل كامل'],
            [$v9->id, 'fuel',       30.000, '2026-05-10', 'تعبئة وقود'],
        ];
        foreach ($expenses as [$vId, $type, $amt, $date, $desc]) {
            VehicleExpense::firstOrCreate(
                ['vehicle_id' => $vId, 'expense_date' => $date, 'expense_type' => $type, 'company_id' => $eagle->id],
                [
                    'vehicle_id' => $vId, 'expense_type' => $type, 'amount' => $amt,
                    'expense_date' => $date, 'description' => $desc,
                    'company_id' => $eagle->id,
                ]
            );
        }
        $this->command->info('✓ Vehicle expenses: KW-7777=95, KW-8888=5, KW-9999=30');
    }
}
