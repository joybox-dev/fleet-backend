<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdvanceDeduction;
use App\Models\ConsolidatedPayrollDeduction;
use App\Models\ConsolidatedPayrollRun;
use App\Models\Contract;
use App\Models\ContractPayrollAdjustment;
use App\Models\ContractPayrollRun;
use App\Models\CustodyItem;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\MaintenanceRecord;
use App\Models\PayrollDeductionOverride;
use App\Models\PayrollDisbursement;
use App\Models\SalaryAdvance;
use App\Models\Violation;
use App\Services\CompanyDeductionService;
use App\Services\ConsolidatedSheetService;
use App\Services\ContractSheetService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The payroll endpoints. The figures come from ContractSheetService and ConsolidatedSheetService;
 * what is left here is who may ask, and the approval that freezes a month.
 */
class PayrollController extends Controller
{
    /**
     * GET /api/payroll/contract-sheet/{contract}
     */
    public function contractSheet(Request $request, $contractId): JsonResponse
    {
        if (! $request->user()->can('contract_payroll.view') && ! $request->user()->can('payroll.view') && ! $request->user()->can('contracts.view')) {
            return response()->json(['message' => 'غير مصرح لك باستعراض كشف رواتب العقود.'], 403);
        }

        $contract = Contract::find($contractId);
        if (! $contract) {
            return response()->json(['message' => 'العقد غير موجود.'], 404);
        }

        return response()->json(ContractSheetService::build(
            $contract,
            (int) $request->input('year', date('Y')),
            (int) $request->input('month', date('n')),
            $this->currentCompanyId()
        ));
    }

    /**
     * POST /api/payroll/contract-sheet/{contract}/approve
     * Approve and freeze contract payroll sheet for a month.
     */
    public function approveContractSheet(Request $request, $contractId): JsonResponse
    {
        // Approving freezes a month's pay. `contract_payroll.edit` deliberately does NOT grant
        // it — editing a sheet and signing it off are different authorities.
        if (! $request->user()->can('contract_payroll.approve') && ! $request->user()->can('payroll.edit')) {
            return response()->json(['message' => 'غير مصرح لك باعتماد كشف رواتب العقد.'], 403);
        }

        $contract = Contract::findOrFail($contractId);
        $companyId = $this->currentCompanyId();
        $year = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));
        $notes = $request->input('notes');

        // Run live calculation of contract sheet to create fresh snapshot
        $data = ContractSheetService::build($contract, $year, $month, $companyId);

        $summary = $data['summary'] ?? [];
        $drivers = $data['drivers'] ?? [];

        // Work with no price on it cannot be signed off. Approving freezes what each driver was
        // owed, and a driver whose orders no rule covers is frozen at nothing for them — a figure
        // that can only be corrected by reopening the month. The pricing gets completed first.
        // A month already approved is served from its frozen snapshot, and re-approving it only
        // rewrites the same figures. It is not re-judged here: this gate is about what is being
        // frozen now, not about re-opening what somebody already signed.
        $blockers = empty($data['is_approved']) ? ContractSheetService::approvalBlockers($drivers) : [];

        if (! empty($blockers)) {
            $totalUnpriced = array_sum(array_column($blockers, 'unpriced_orders'));

            return response()->json([
                'message' => 'لا يمكن اعتماد الكشف: '.$totalUnpriced.' طلب لدى '.count($blockers)
                    .' سائق لا تنطبق عليها أي قاعدة تسعير في هذا العقد. أكمل التسعير أولاً — الاعتماد يجمّد أجر هؤلاء عند صفر لهذه الطلبات.',
                'approval_blockers' => $blockers,
            ], 422);
        }

        $run = ContractPayrollRun::updateOrCreate(
            [
                'company_id' => $companyId,
                'contract_id' => $contract->id,
                'year' => $year,
                'month' => $month,
            ],
            [
                'status' => 'approved',
                'total_drivers' => count($drivers),
                'total_orders' => (int) ($summary['total_orders'] ?? 0),
                'total_gross_earnings' => (float) ($summary['total_gross_earnings'] ?? 0.0),
                'total_violations_deductions' => (float) ($summary['total_violations_deductions'] ?? $summary['total_global_deductions'] ?? 0.0),
                'total_net_payout' => (float) ($summary['total_net_payout'] ?? 0.0),
                'snapshot_data' => $data,
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
                'notes' => $notes,
            ]
        );

        return response()->json([
            'message' => "تم اعتماد وتجميد كشف رواتب العقد ({$contract->name}) لشهر {$month}/{$year} بنجاح 🔒",
            'run' => $run->load('approvedBy:id,name'),
        ]);
    }

    /**
     * POST /api/payroll/contract-sheet/{contract}/unapprove
     * Unapprove (un-freeze) contract payroll sheet.
     */
    public function unapproveContractSheet(Request $request, $contractId): JsonResponse
    {
        // Unapproving deletes the frozen snapshot, so it needs at least the authority that
        // created it.
        if (! $request->user()->can('contract_payroll.approve') && ! $request->user()->can('payroll.edit')) {
            return response()->json(['message' => 'غير مصرح لك بفك اعتماد كشف رواتب العقد.'], 403);
        }

        $contract = Contract::findOrFail($contractId);
        $year = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));

        $run = ContractPayrollRun::where('company_id', $this->currentCompanyId())
            ->where('contract_id', $contract->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($run) {
            $run->delete();
        }

        return response()->json([
            'message' => "تم فك تجميد واعتماد كشف رواتب العقد ({$contract->name}) لشهر {$month}/{$year} بنجاح.",
        ]);
    }

    /**
     * GET /api/payroll/contract-sheet/{contract}/adjustments
     */
    public function getContractAdjustments(Request $request, $contractId): JsonResponse
    {
        $contract = Contract::findOrFail($contractId);
        $year = (int) $request->input('year', date('Y'));
        $month = (int) $request->input('month', date('n'));

        $adjustments = ContractPayrollAdjustment::withoutGlobalScopes()
            ->where('contract_id', $contract->id)
            ->where('year', $year)
            ->where('month', $month)
            ->with(['employee:id,name,employee_number', 'createdBy:id,name'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json($adjustments);
    }

    /**
     * POST /api/payroll/contract-sheet/{contract}/adjustments
     */
    public function storeContractAdjustment(Request $request, $contractId): JsonResponse
    {
        if (! $request->user()->can('contract_payroll.edit') && ! $request->user()->can('payroll.edit')) {
            return response()->json(['message' => 'غير مصرح لك بإضافة تسويات رواتب.'], 403);
        }

        $contract = Contract::findOrFail($contractId);
        $companyId = $this->currentCompanyId();

        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'year' => 'required|integer|min:2020|max:2030',
            'month' => 'required|integer|min:1|max:12',
            'type' => 'required|in:addition,deduction',
            'amount' => 'required|numeric|min:0.001',
            'reason' => 'required|string|max:500',
        ]);

        // Check if contract is already approved/frozen for this month
        $approvedRun = ContractPayrollRun::where('company_id', $companyId)
            ->where('contract_id', $contract->id)
            ->where('year', $validated['year'])
            ->where('month', $validated['month'])
            ->where('status', 'approved')
            ->first();

        if ($approvedRun) {
            return response()->json(['message' => 'لا يمكن إضافة تسوية لأن كشف رواتب العقد معتمد ومجمد.'], 422);
        }

        $adjustment = ContractPayrollAdjustment::create([
            'company_id' => $companyId,
            'contract_id' => $contract->id,
            'employee_id' => $validated['employee_id'],
            'year' => $validated['year'],
            'month' => $validated['month'],
            'type' => $validated['type'],
            'amount' => $validated['amount'],
            'reason' => $validated['reason'],
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'تمت إضافة التسوية بنجاح.',
            'adjustment' => $adjustment->load(['employee:id,name,employee_number', 'createdBy:id,name']),
        ], 201);
    }

    /**
     * DELETE /api/payroll/contract-sheet/adjustments/{adjustment}
     */
    public function destroyContractAdjustment(Request $request, $adjustmentId): JsonResponse
    {
        if (! $request->user()->can('contract_payroll.edit') && ! $request->user()->can('payroll.edit')) {
            return response()->json(['message' => 'غير مصرح لك بحذف تسويات رواتب.'], 403);
        }

        $adjustment = ContractPayrollAdjustment::findOrFail($adjustmentId);

        $approvedRun = ContractPayrollRun::where('contract_id', $adjustment->contract_id)
            ->where('year', $adjustment->year)
            ->where('month', $adjustment->month)
            ->where('status', 'approved')
            ->first();

        if ($approvedRun) {
            return response()->json(['message' => 'لا يمكن حذف التسوية لأن الكشف معتمد ومجمد.'], 422);
        }

        $adjustment->delete();

        return response()->json(['message' => 'تم حذف التسوية بنجاح.']);
    }

    /**
     * GET /api/payroll/consolidated/{year}/{month}
     * Consolidated Monthly Payroll Sheet based strictly on Approved Contract Payroll Runs.
     */
    public function consolidatedSheet(Request $request, $year, $month): JsonResponse
    {
        return response()->json(ConsolidatedSheetService::build($this->currentCompanyId(), (int) $year, (int) $month));
    }

    /**
     * POST /api/payroll/consolidated/{year}/{month}/approve
     *
     * Freezes the company-wide sheet and commits the deductions it projected: traffic
     * fines are marked deducted and salary-advance instalments are recorded against the
     * run, paying the advance down. This is the only place either happens on the contract
     * payroll path, so an unapproved month never touches a driver's balance.
     */
    public function approveConsolidatedSheet(Request $request, $year, $month): JsonResponse
    {
        if (! $request->user()->can('contract_payroll.approve') && ! $request->user()->can('payroll.edit')) {
            return response()->json(['message' => 'غير مصرح لك باعتماد كشف الرواتب المجمّع.'], 403);
        }

        $year = (int) $year;
        $month = (int) $month;
        $companyId = $this->currentCompanyId();

        $existing = ConsolidatedPayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'approved')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => "كشف الرواتب المجمّع لشهر {$month}/{$year} معتمد بالفعل. افكّ الاعتماد أولاً لإعادة احتسابه.",
            ], 422);
        }

        // Months may be closed in any order. A month approved late is not stranded: the charges it
        // finds are whatever is still outstanding when it runs, and what it cannot collect stays on
        // the driver and is taken by the next month approved after it. Calendar order is not what
        // links the months together — approval order is.

        $startDate = sprintf('%04d-%02d-01', $year, $month);
        $endDate = sprintf('%04d-%02d-%02d', $year, $month, Carbon::parse($startDate)->daysInMonth);

        // Re-run the projection so the frozen snapshot reflects the data as of approval.
        $data = ConsolidatedSheetService::build($companyId, $year, $month);
        $drivers = $data['drivers'] ?? [];

        if (empty($drivers)) {
            return response()->json([
                'message' => 'لا يوجد سائقون في الكشف المجمّع لهذا الشهر. اعتمد كشوف العقود أولاً.',
            ], 422);
        }

        $run = \DB::transaction(function () use ($request, $companyId, $year, $month, $startDate, $endDate, $drivers, $data) {
            $run = ConsolidatedPayrollRun::create([
                'company_id' => $companyId,
                'year' => $year,
                'month' => $month,
                'status' => 'approved',
                'approved_by' => $request->user()?->id,
                'approved_at' => now(),
                'notes' => $request->input('notes'),
            ]);

            $employeeIds = array_values(array_filter(array_column($drivers, 'employee_id')));

            // Re-resolve rather than trusting the projection passed in: between opening the
            // sheet and pressing approve, a fine may have been settled or an advance closed.
            $pending = CompanyDeductionService::pendingFor($employeeIds, $startDate, $endDate, $year, $month);

            $chargedViolationIds = [];
            $chargedExpenseIds = [];

            foreach ($pending as $employeeId => $bucket) {
                $employee = Employee::withoutGlobalScopes()->find($employeeId);

                foreach ($bucket['items'] as $item) {
                    $amount = (float) $item['amount'];
                    $type = $item['source_type'];
                    $sourceId = $item['source_id'];

                    if ($type === ConsolidatedPayrollDeduction::SOURCE_ADVANCE) {
                        $advance = SalaryAdvance::withoutGlobalScopes()->find($sourceId);
                        if (! $advance) {
                            continue;
                        }
                        // The final instalment collects only the principal that is left.
                        $amount = min($amount, (float) $advance->remaining_balance);
                        if ($amount <= 0) {
                            continue;
                        }

                        AdvanceDeduction::create([
                            'salary_advance_id' => $advance->id,
                            'payroll_slip_id' => null,
                            'consolidated_run_id' => $run->id,
                            'amount' => $amount,
                            'deduction_date' => $endDate,
                            'company_id' => $advance->company_id,
                        ]);

                        $advance->paid_installments = (int) $advance->paid_installments + 1;
                        $advance->remaining_balance = max(0, (float) $advance->remaining_balance - $amount);
                        if ($advance->remaining_balance <= 0) {
                            $advance->status = 'completed';
                        }
                        $advance->saveQuietly();
                    }

                    if ($type === ConsolidatedPayrollDeduction::SOURCE_VIOLATION) {
                        $chargedViolationIds[] = $sourceId;
                    }
                    if ($type === ConsolidatedPayrollDeduction::SOURCE_DRIVER_EXPENSE) {
                        $chargedExpenseIds[] = $sourceId;
                    }

                    ConsolidatedPayrollDeduction::create([
                        'company_id' => $employee?->company_id ?? $companyId,
                        'consolidated_run_id' => $run->id,
                        'employee_id' => $employeeId,
                        'source_type' => $type,
                        'source_id' => $sourceId,
                        'amount' => $amount,
                        'label' => $item['label'],
                    ]);
                }
            }

            // Only what THIS run charged is flagged — anything already true was collected
            // elsewhere, and unapproving must not release someone else's deduction.
            if (! empty($chargedViolationIds)) {
                Violation::withoutGlobalScopes()->whereIn('id', $chargedViolationIds)->update(['is_deducted' => true]);
            }
            if (! empty($chargedExpenseIds)) {
                DriverExpense::withoutGlobalScopes()->whereIn('id', $chargedExpenseIds)->update(['is_deducted' => true]);
            }

            // Freeze the sheet as it stands AFTER the deductions were applied. Re-projecting
            // an approved month would forget what it collected the moment an advance closes.
            $ledger = ConsolidatedPayrollDeduction::withoutGlobalScopes()
                ->where('consolidated_run_id', $run->id)
                ->get()
                ->groupBy('employee_id');

            $totals = ['gross' => 0.0, 'adjustments' => 0.0, 'net' => 0.0, 'orders' => 0, 'deductions' => 0.0];
            $byType = [];

            $typeKeys = [
                'violations' => ConsolidatedPayrollDeduction::SOURCE_VIOLATION,
                'maintenance' => ConsolidatedPayrollDeduction::SOURCE_MAINTENANCE,
                'custody' => ConsolidatedPayrollDeduction::SOURCE_CUSTODY,
                'driver_expenses' => ConsolidatedPayrollDeduction::SOURCE_DRIVER_EXPENSE,
                'advances' => ConsolidatedPayrollDeduction::SOURCE_ADVANCE,
            ];

            foreach ($drivers as $i => $d) {
                $empId = $d['employee_id'] ?? null;
                $rows = $ledger->get($empId, collect());
                $charged = round((float) $rows->sum('amount'), 3);

                $gross = round((float) ($d['gross_contract_earnings'] ?? 0.0), 3);
                $adjustments = round((float) ($d['manual_adjustments_total'] ?? 0.0), 3);
                $net = round($gross + $adjustments - $charged, 3);

                $drivers[$i]['deductions_applied'] = true;
                $drivers[$i]['final_net_payout'] = $net;
                $drivers[$i]['deductions_total'] = $charged;
                $drivers[$i]['pending_deductions_total'] = $charged;
                $drivers[$i]['deduction_items'] = $rows->map(fn ($r) => [
                    'source_type' => $r->source_type,
                    'source_id' => $r->source_id,
                    'amount' => (float) $r->amount,
                    'label' => $r->label,
                ])->values()->all();

                foreach ($typeKeys as $key => $type) {
                    $amount = round((float) $rows->where('source_type', $type)->sum('amount'), 3);
                    $drivers[$i]["{$key}_deduction"] = $amount;
                    $drivers[$i]["pending_{$key}_deduction"] = $amount;
                    $byType[$key] = round(($byType[$key] ?? 0.0) + $amount, 3);
                }

                $totals['gross'] += $gross;
                $totals['adjustments'] += $adjustments;
                $totals['deductions'] += $charged;
                $totals['orders'] += (int) ($d['orders_count'] ?? 0);
                $totals['net'] += $net;
            }

            $data['drivers'] = $drivers;
            $data['is_approved'] = true;
            $data['deductions_applied'] = true;
            $data['summary'] = array_merge($data['summary'] ?? [], [
                'total_final_net_payout' => round($totals['net'], 3),
                'total_deductions' => round($totals['deductions'], 3),
                'total_pending_deductions' => round($totals['deductions'], 3),
                'total_violations_deductions' => $byType['violations'] ?? 0.0,
                'total_advances_deductions' => $byType['advances'] ?? 0.0,
                'total_pending_violations_deductions' => $byType['violations'] ?? 0.0,
                'total_pending_advances_deductions' => $byType['advances'] ?? 0.0,
                'total_pending_maintenance_deductions' => $byType['maintenance'] ?? 0.0,
                'total_pending_custody_deductions' => $byType['custody'] ?? 0.0,
                'total_pending_driver_expenses_deductions' => $byType['driver_expenses'] ?? 0.0,
            ]);

            $run->update([
                'total_drivers' => count($drivers),
                'total_orders' => $totals['orders'],
                'total_gross_earnings' => round($totals['gross'], 3),
                'total_violations_deductions' => $byType['violations'] ?? 0.0,
                'total_advances_deductions' => $byType['advances'] ?? 0.0,
                'total_manual_adjustments' => round($totals['adjustments'], 3),
                'total_final_net_payout' => round($totals['net'], 3),
                'snapshot_data' => $data,
            ]);

            return $run;
        });

        return response()->json([
            'message' => "تم اعتماد كشف الرواتب المجمّع لشهر {$month}/{$year} وتطبيق خصومات المخالفات والسلف 🔒",
            'run' => $run->load('approvedBy:id,name'),
        ]);
    }

    /**
     * POST /api/payroll/consolidated/{year}/{month}/unapprove
     *
     * Reverses everything approve() committed: instalments are refunded to the advance,
     * fines are un-marked, and the month falls back to a projection.
     */
    public function unapproveConsolidatedSheet(Request $request, $year, $month): JsonResponse
    {
        if (! $request->user()->can('contract_payroll.approve') && ! $request->user()->can('payroll.edit')) {
            return response()->json(['message' => 'غير مصرح لك بفك اعتماد كشف الرواتب المجمّع.'], 403);
        }

        $year = (int) $year;
        $month = (int) $month;
        $companyId = $this->currentCompanyId();

        $run = ConsolidatedPayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if (! $run) {
            return response()->json(['message' => 'لا يوجد اعتماد لكشف هذا الشهر.'], 404);
        }

        // Only the month approved most recently may be reopened — most recently in time, not in the
        // calendar. Months carry a balance forward in the order they were closed, so each approval
        // reads the balance the one before it left. Reopening any but the last would restore a
        // balance that later approvals have already spent, and the ones after it would be sitting
        // on an opening figure that no longer exists. The run row is created at approval, so a
        // higher id is simply a later approval.
        $newer = ConsolidatedPayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'approved')
            ->where('id', '>', $run->id)
            ->orderByDesc('id')
            ->first();

        if ($newer) {
            return response()->json([
                'message' => "لا يمكن فكّ اعتماد شهر {$month}/{$year} لأنّ شهر {$newer->month}/{$newer->year} اعتُمد بعده. "
                    .'ابدأ بفكّ اعتماد آخر شهر تمّ اعتماده.',
            ], 422);
        }

        // Money already handed over cannot be un-happened by reopening the month. The payments
        // have to be deleted first, deliberately and one by one, so nobody reopens a paid month
        // and loses the record of what was paid.
        $paidCount = PayrollDisbursement::withoutGlobalScopes()->where('consolidated_run_id', $run->id)->count();
        if ($paidCount > 0) {
            return response()->json([
                'message' => "سُجِّل صرف رواتب على شهر {$month}/{$year} ({$paidCount} حركة صرف). احذف حركات الصرف أولاً قبل فكّ الاعتماد.",
            ], 422);
        }

        \DB::transaction(function () use ($run) {
            foreach ($run->advanceDeductions()->get() as $deduction) {
                $advance = SalaryAdvance::withoutGlobalScopes()->find($deduction->salary_advance_id);
                if ($advance) {
                    $advance->remaining_balance = (float) $advance->remaining_balance + (float) $deduction->amount;
                    $advance->paid_installments = max(0, (int) $advance->paid_installments - 1);
                    if ($advance->status === 'completed' && $advance->remaining_balance > 0) {
                        $advance->status = 'active';
                    }
                    $advance->saveQuietly();
                }
                $deduction->delete();
            }

            // Release exactly what the ledger says this run charged, and nothing else. Anything
            // collected elsewhere has no ledger row here, so its flag survives and cannot be made
            // billable again by unapproving this month.
            $rows = ConsolidatedPayrollDeduction::withoutGlobalScopes()
                ->where('consolidated_run_id', $run->id)
                ->get();

            $violationIds = $rows->where('source_type', ConsolidatedPayrollDeduction::SOURCE_VIOLATION)
                ->pluck('source_id')->filter()->all();
            if (! empty($violationIds)) {
                Violation::withoutGlobalScopes()->whereIn('id', $violationIds)->update(['is_deducted' => false]);
            }

            $expenseIds = $rows->where('source_type', ConsolidatedPayrollDeduction::SOURCE_DRIVER_EXPENSE)
                ->pluck('source_id')->filter()->all();
            if (! empty($expenseIds)) {
                DriverExpense::withoutGlobalScopes()->whereIn('id', $expenseIds)->update(['is_deducted' => false]);
            }

            // Maintenance, custody and leave carry no flag of their own — removing the ledger
            // rows is what makes them outstanding again. Deleted explicitly rather than relying
            // on the FK cascade, which is not guaranteed to be enforced on every connection.
            ConsolidatedPayrollDeduction::withoutGlobalScopes()
                ->where('consolidated_run_id', $run->id)
                ->delete();

            $run->delete();
        });

        return response()->json([
            'message' => "تم فك اعتماد كشف الرواتب المجمّع لشهر {$month}/{$year} وإرجاع خصومات المخالفات والسلف 🔓",
        ]);
    }

    /**
     * POST /api/payroll/consolidated/{year}/{month}/disbursements
     *
     * Records money handed to a driver against an approved month: so much by bank transfer, so
     * much in cash, on the day it was paid. Approval fixed what he was owed; this is the record of
     * what he was given, and the only thing that takes a month off his balance. The amount is the
     * payer's decision, not the sheet's suggestion — part of a month despite a debt, or more than
     * the month — and whatever is left either way stays on his account.
     */
    public function storeDisbursement(Request $request, $year, $month): JsonResponse
    {
        $run = ConsolidatedPayrollRun::withoutGlobalScopes()
            ->where('company_id', $this->currentCompanyId())
            ->where('year', (int) $year)
            ->where('month', (int) $month)
            ->where('status', 'approved')
            ->first();

        if (! $run) {
            return response()->json([
                'message' => "كشف شهر {$month}/{$year} غير معتمد — لا يُسجَّل صرف إلا على شهر معتمد.",
            ], 422);
        }

        $data = $this->validatedDisbursement($request, true);

        $onSheet = collect($run->snapshot_data['drivers'] ?? [])
            ->contains(fn ($d) => (int) ($d['employee_id'] ?? 0) === $data['employee_id']);
        if (! $onSheet) {
            return response()->json(['message' => 'هذا السائق ليس في كشف الشهر المعتمد.'], 422);
        }

        $this->assertBankWithinSalary($run->id, $data['employee_id'], $data['bank_amount']);

        $disbursement = PayrollDisbursement::create([
            'company_id' => $run->company_id,
            'consolidated_run_id' => $run->id,
            'employee_id' => $data['employee_id'],
            'bank_amount' => $data['bank_amount'],
            'cash_amount' => $data['cash_amount'],
            'paid_at' => $data['paid_at'],
            'notes' => $data['notes'],
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'تم تسجيل الصرف.',
            'disbursement' => $disbursement->load('createdBy:id,name')->toRow(),
        ], 201);
    }

    /**
     * PUT /api/payroll/disbursements/{disbursement}
     *
     * Corrects a recorded payment. The month it belongs to does not change.
     */
    public function updateDisbursement(Request $request, PayrollDisbursement $disbursement): JsonResponse
    {
        $data = $this->validatedDisbursement($request, false);
        $this->assertBankWithinSalary(
            (int) $disbursement->consolidated_run_id,
            (int) $disbursement->employee_id,
            $data['bank_amount'],
            $disbursement->id
        );

        $disbursement->update([
            'bank_amount' => $data['bank_amount'],
            'cash_amount' => $data['cash_amount'],
            'paid_at' => $data['paid_at'],
            'notes' => $data['notes'],
        ]);

        return response()->json([
            'message' => 'تم تعديل حركة الصرف.',
            'disbursement' => $disbursement->load('createdBy:id,name')->toRow(),
        ]);
    }

    /**
     * DELETE /api/payroll/disbursements/{disbursement}
     */
    public function destroyDisbursement(PayrollDisbursement $disbursement): JsonResponse
    {
        $disbursement->delete();

        return response()->json(['message' => 'تم حذف حركة الصرف.']);
    }

    /**
     * POST /api/payroll/consolidated/{year}/{month}/deduction-overrides
     *
     * The owner's word on one charge before the month is approved: take it in a later month, or
     * take a different instalment of an advance this month. Recorded with a reason and a name,
     * read by the approval, kept through an unapproval. It never waives a charge: a fine the
     * driver should not pay is corrected on the fine itself, where the record lives.
     */
    public function storeDeductionOverride(Request $request, $year, $month): JsonResponse
    {
        $year = (int) $year;
        $month = (int) $month;
        $companyId = $this->currentCompanyId();

        if ($this->monthIsApproved($companyId, $year, $month)) {
            return response()->json(['message' => "شهر {$month}/{$year} معتمد — فكّ اعتماده أولاً لتغيير قرارات الخصم."], 422);
        }

        $data = $request->validate([
            'source_type' => 'required|in:violation,maintenance,custody,driver_expense,advance',
            'source_id' => 'required|integer',
            'action' => 'required|in:defer,amount',
            'amount' => 'nullable|numeric|min:0',
            'defer_to_year' => 'nullable|integer|min:2020|max:2100',
            'defer_to_month' => 'nullable|integer|min:1|max:12',
            'reason' => 'required|string|max:500',
        ]);

        $source = $this->deductionSource($data['source_type'], (int) $data['source_id'], $companyId);
        if (! $source) {
            return response()->json(['message' => 'البند غير موجود.'], 422);
        }

        $chargedAlready = ($data['source_type'] !== 'advance' && ConsolidatedPayrollDeduction::withoutGlobalScopes()
            ->where('source_type', $data['source_type'])->where('source_id', $source->id)->exists())
            || (in_array($data['source_type'], ['violation', 'driver_expense'], true) && $source->is_deducted);
        if ($chargedAlready) {
            return response()->json(['message' => 'هذا البند خُصم فعلاً في شهر معتمد — لا قرار عليه.'], 422);
        }

        $values = ['action' => $data['action'], 'amount' => null, 'defer_to_year' => null, 'defer_to_month' => null];

        if ($data['action'] === 'defer') {
            $toYear = (int) ($data['defer_to_year'] ?? 0);
            $toMonth = (int) ($data['defer_to_month'] ?? 0);
            if (! $toYear || ! $toMonth) {
                throw ValidationException::withMessages(['defer_to_month' => 'حدّد الشهر الذي يُخصم فيه.']);
            }
            if (PayrollDeductionOverride::index($toYear, $toMonth) <= PayrollDeductionOverride::index($year, $month)) {
                throw ValidationException::withMessages(['defer_to_month' => 'التأجيل يكون إلى شهر بعد هذا الشهر.']);
            }
            if ($this->monthIsApproved($companyId, $toYear, $toMonth)) {
                throw ValidationException::withMessages(['defer_to_month' => "شهر {$toMonth}/{$toYear} معتمد سلفاً — اختر شهراً مفتوحاً."]);
            }
            $values['defer_to_year'] = $toYear;
            $values['defer_to_month'] = $toMonth;
        } else {
            if ($data['source_type'] !== 'advance') {
                throw ValidationException::withMessages(['action' => 'تعديل المبلغ متاح لأقساط السلف فقط؛ المخالفة أو المصروف يُعدَّل على سجله نفسه.']);
            }
            $amount = round((float) ($data['amount'] ?? 0), 3);
            if ($amount > (float) $source->remaining_balance + 0.0005) {
                throw ValidationException::withMessages(['amount' => 'المبلغ أكبر من المتبقي على السلفة ('.number_format((float) $source->remaining_balance, 3).').']);
            }
            $values['amount'] = $amount;
        }

        $override = PayrollDeductionOverride::updateOrCreate(
            ['company_id' => $companyId, 'year' => $year, 'month' => $month, 'source_type' => $data['source_type'], 'source_id' => $source->id],
            $values + ['reason' => $data['reason'], 'created_by' => $request->user()?->id]
        );

        return response()->json([
            'message' => $data['action'] === 'defer' ? 'سُجِّل التأجيل.' : 'سُجِّل قسط هذا الشهر.',
            'override' => $override->load('createdBy:id,name')->toRow(),
        ], 201);
    }

    /**
     * DELETE /api/payroll/deduction-overrides/{override}
     */
    public function destroyDeductionOverride(PayrollDeductionOverride $override): JsonResponse
    {
        if ($this->monthIsApproved((int) $override->company_id, (int) $override->year, (int) $override->month)) {
            return response()->json(['message' => 'الشهر معتمد — فكّ اعتماده أولاً.'], 422);
        }

        $override->delete();

        return response()->json(['message' => 'أُلغي القرار؛ يعود البند إلى قاعدته الأصلية.']);
    }

    private function monthIsApproved(int $companyId, int $year, int $month): bool
    {
        return ConsolidatedPayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'approved')
            ->exists();
    }

    private function deductionSource(string $type, int $id, int $companyId): ?object
    {
        $model = match ($type) {
            'violation' => Violation::class,
            'maintenance' => MaintenanceRecord::class,
            'custody' => CustodyItem::class,
            'driver_expense' => DriverExpense::class,
            'advance' => SalaryAdvance::class,
        };

        return $model::withoutGlobalScopes()->whereNull('deleted_at')->where('company_id', $companyId)->find($id);
    }

    /**
     * A payment is bank, cash, or both — never nothing. A month a driver is not paid for needs no
     * record: an approved month with no payment against it is, by itself, the record that he is
     * still owed it.
     *
     * @return array{employee_id: int, bank_amount: float, cash_amount: float, paid_at: string, notes: ?string}
     */
    private function validatedDisbursement(Request $request, bool $withEmployee): array
    {
        $data = $request->validate(array_merge($withEmployee ? ['employee_id' => 'required|integer'] : [], [
            'bank_amount' => 'nullable|numeric|min:0',
            'cash_amount' => 'nullable|numeric|min:0',
            'paid_at' => 'nullable|date',
            'notes' => 'nullable|string|max:500',
        ]));

        $bank = round((float) ($data['bank_amount'] ?? 0), 3);
        $cash = round((float) ($data['cash_amount'] ?? 0), 3);

        if ($bank + $cash <= 0) {
            throw ValidationException::withMessages([
                'bank_amount' => 'أدخل مبلغاً بنكياً أو نقدياً — لا يُسجَّل صرف بصفر.',
            ]);
        }

        return [
            'employee_id' => (int) ($data['employee_id'] ?? 0),
            'bank_amount' => $bank,
            'cash_amount' => $cash,
            'paid_at' => ! empty($data['paid_at']) ? Carbon::parse($data['paid_at'])->toDateString() : now()->toDateString(),
            'notes' => $data['notes'] ?? null,
        ];
    }

    /**
     * The owner's rule on the bank side of a payment: what goes through the bank in a month is at
     * most the driver's registered salary — the bank and the ministry see that figure and nothing
     * above it — and the rest of what he is owed is handed over in cash. Measured against the
     * month's other transfers, so a correction is judged without counting itself.
     */
    private function assertBankWithinSalary(int $runId, int $employeeId, float $bank, ?int $excludeId = null): void
    {
        if ($bank <= 0) {
            return;
        }

        $employee = Employee::withoutGlobalScopes()->withTrashed()->find($employeeId);
        $allowance = $employee
            ? PayrollDisbursement::bankAllowance($employee, $runId, $excludeId)
            : ['salary' => 0.0, 'transferred' => 0.0, 'available' => 0.0];

        if ($bank <= $allowance['available'] + 0.0005) {
            return;
        }

        $salary = number_format($allowance['salary'], 3, '.', '').' د.ك';
        $transferred = number_format($allowance['transferred'], 3, '.', '').' د.ك';
        $available = number_format($allowance['available'], 3, '.', '').' د.ك';

        $message = match (true) {
            $allowance['salary'] <= 0 => 'لا راتب بنكي مسجَّل لهذا السائق — سجّل راتبه الرسمي في ملفه، أو اصرف المبلغ نقداً.',
            $allowance['transferred'] > 0 => "التحويل البنكي لا يتجاوز الراتب البنكي للسائق ({$salary}): حُوِّل له هذا الشهر {$transferred} والمتاح {$available} — الباقي يُصرف نقداً.",
            default => "التحويل البنكي لا يتجاوز الراتب البنكي للسائق ({$salary}) — الباقي يُصرف نقداً.",
        };

        throw ValidationException::withMessages(['bank_amount' => $message]);
    }
}
