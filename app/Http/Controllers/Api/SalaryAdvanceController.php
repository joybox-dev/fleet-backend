<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdvanceDeduction;
use App\Models\Employee;
use App\Models\SalaryAdvance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SalaryAdvanceController extends Controller
{
    /**
     * GET /api/salary-advances
     */
    public function index(Request $request): JsonResponse
    {
        $query = SalaryAdvance::with([
            'employee:id,name,name_ar,employee_number',
            'approver:id,name',
            'transferredTo:id,employee_id', 'transferredTo.employee:id,name',
            'transferredFrom:id,employee_id', 'transferredFrom.employee:id,name',
        ]);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('employee_id')) {
            $query->where('employee_id', $request->employee_id);
        }

        $advances = $query->orderByDesc('advance_date')->get();

        return response()->json($advances);
    }

    /**
     * POST /api/salary-advances
     */
    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('salary_advances.create')) {
            return response()->json(['message' => 'غير مصرح لك بإضافة سلفة جـديدة.'], 403);
        }

        $validated = $request->validate([
            'employee_id' => 'required|exists_in_company:employees,id',
            'amount' => 'required|numeric|min:1',
            'monthly_installment' => 'required|numeric|min:0.001',
            'advance_date' => 'required|date',
            'reason' => 'nullable|string|max:500',
            'purpose' => ['nullable', Rule::in(array_keys(SalaryAdvance::PURPOSES))],
            // Cash leaves the company here. The only record that the driver received it used to be
            // the row created by whoever handed it over; now the signed voucher goes with it.
            'voucher_path' => 'required|string|max:255',
        ], [
            // The default read «The voucher path field is required» — an English sentence naming a
            // database column, on an Arabic screen, and it was also what came back when the upload
            // itself had failed. It has to say what to do about it.
            'voucher_path.required' => 'يجب إرفاق سند الصرف الموقّع قبل حفظ السلفة — وهو إثبات استلام السائق للمبلغ نقداً.',
            'employee_id.required' => 'يجب اختيار الموظف.',
            'amount.required' => 'يجب إدخال مبلغ السلفة.',
            'amount.min' => 'مبلغ السلفة يجب أن يكون دينـاراً واحداً على الأقل.',
            'monthly_installment.required' => 'يجب إدخال القسط الشهري.',
            'monthly_installment.min' => 'القسط الشهري يجب أن يكون أكبر من صفر.',
            'advance_date.required' => 'يجب تحديد تاريخ السلفة.',
        ]);

        // A driver may hold several advances at once — cash, a phone, a line — each collected on its
        // own schedule. One active advance per driver used to be the rule, which forced all of them
        // into a single figure that could not be stopped or moved in part (client, 2026-11).
        $validated['purpose'] = $validated['purpose'] ?? 'cash';

        $amount = (float) $validated['amount'];
        $installment = (float) $validated['monthly_installment'];

        $validated['total_installments'] = (int) ceil($amount / $installment);
        $validated['paid_installments'] = 0;
        $validated['remaining_balance'] = $amount;
        $validated['status'] = 'active';
        $validated['approved_by'] = $request->user()->id;

        $advance = SalaryAdvance::create($validated);

        $advance->load(['employee:id,name', 'approver:id,name']);

        return response()->json($advance, 201);
    }

    /**
     * GET /api/salary-advances/{salaryAdvance}
     */
    public function show(SalaryAdvance $salaryAdvance): JsonResponse
    {
        $salaryAdvance->load([
            'employee:id,name',
            'approver:id,name',
            'transferredTo:id,employee_id', 'transferredTo.employee:id,name',
            'transferredFrom:id,employee_id', 'transferredFrom.employee:id,name',
            'deductions' => fn ($q) => $q->orderByDesc('deduction_date'),
        ]);

        return response()->json($salaryAdvance);
    }

    /**
     * POST /api/salary-advances/{salaryAdvance}/cancel
     */
    public function cancel(Request $request, SalaryAdvance $salaryAdvance): JsonResponse
    {
        if (! $request->user()->can('salary_advances.edit')) {
            return response()->json(['message' => 'غير مصرح لك بإلغاء السلف.'], 403);
        }

        if ($salaryAdvance->status !== 'active') {
            return response()->json([
                'message' => 'لا يمكن إلغاء سلفة غير فعّالة.',
            ], 422);
        }

        $salaryAdvance->update(['status' => 'cancelled']);

        return response()->json([
            'message' => 'تم إلغاء السلفة.',
            'data' => $salaryAdvance,
        ]);
    }

    /**
     * POST /api/salary-advances/{salaryAdvance}/stop — the line is cut, the phone handed back.
     *
     * What payroll already took stays taken; what was left is no longer owed and no sheet collects
     * it from here on, the open month included. Unlike cancel, which says the advance should never
     * have been, a stopped advance keeps its instalments and records the balance it dropped.
     */
    public function stop(Request $request, SalaryAdvance $salaryAdvance): JsonResponse
    {
        if (! $request->user()->can('salary_advances.edit')) {
            return response()->json(['message' => 'غير مصرح لك بإيقاف السلف.'], 403);
        }

        $data = $request->validate([
            'date' => 'nullable|date',
            'reason' => 'required|string|max:500',
        ], ['reason.required' => 'اكتب سبب الإيقاف.']);

        DB::transaction(function () use ($salaryAdvance, $data) {
            $advance = SalaryAdvance::whereKey($salaryAdvance->id)->lockForUpdate()->firstOrFail();
            $this->mustBeActive($advance);

            $advance->update([
                'status' => SalaryAdvance::STATUS_STOPPED,
                'closed_at' => $data['date'] ?? now()->toDateString(),
                'closed_balance' => $advance->remaining_balance,
                'closed_reason' => $data['reason'],
                'remaining_balance' => 0,
            ]);
        });

        return response()->json([
            'message' => 'أُوقفت السلفة — لن يُخصم منها شيء بعد الآن.',
            'data' => $salaryAdvance->fresh(),
        ]);
    }

    /**
     * POST /api/salary-advances/{salaryAdvance}/transfer — what is left goes on to another driver.
     *
     * The balance becomes a new advance of his, of the same purpose, collected from the month of
     * the transfer on; this one closes with what it had collected. No cash changes hands, so there
     * is no voucher.
     */
    public function transfer(Request $request, SalaryAdvance $salaryAdvance): JsonResponse
    {
        if (! $request->user()->can('salary_advances.edit')) {
            return response()->json(['message' => 'غير مصرح لك بنقل السلف.'], 403);
        }

        $data = $request->validate([
            'employee_id' => 'required|exists_in_company:employees,id',
            'monthly_installment' => 'required|numeric|min:0.001',
            'date' => 'nullable|date',
            'reason' => 'nullable|string|max:500',
        ], [
            'employee_id.required' => 'اختر السائق الذي تنتقل إليه السلفة.',
            'monthly_installment.required' => 'يجب إدخال القسط الشهري.',
        ]);

        $new = DB::transaction(function () use ($salaryAdvance, $data, $request) {
            $advance = SalaryAdvance::whereKey($salaryAdvance->id)->lockForUpdate()->firstOrFail();
            $this->mustBeActive($advance);

            if ((int) $data['employee_id'] === (int) $advance->employee_id) {
                throw ValidationException::withMessages(['employee_id' => 'السلفة على هذا السائق أصلاً — اختر سائقاً آخر.']);
            }

            $date = Carbon::parse($data['date'] ?? now()->toDateString())->toDateString();
            if ($date < $advance->advance_date->toDateString()) {
                throw ValidationException::withMessages(['date' => 'تاريخ النقل قبل تاريخ السلفة نفسها.']);
            }

            $remaining = round((float) $advance->remaining_balance, 3);
            $installment = round((float) $data['monthly_installment'], 3);
            $from = Employee::find($advance->employee_id);

            $new = SalaryAdvance::create([
                'employee_id' => $data['employee_id'],
                'company_id' => $advance->company_id,
                'approved_by' => $request->user()->id,
                'amount' => $remaining,
                'monthly_installment' => $installment,
                'total_installments' => (int) ceil($remaining / $installment),
                'paid_installments' => 0,
                'remaining_balance' => $remaining,
                'advance_date' => $date,
                'purpose' => $advance->purpose ?: 'cash',
                'reason' => 'منقولة من '.($from?->name ?? '—').' (سلفة #'.$advance->id.')'
                    .(! empty($data['reason']) ? ' — '.$data['reason'] : ''),
                'status' => 'active',
                'transferred_from_id' => $advance->id,
            ]);

            $advance->update([
                'status' => SalaryAdvance::STATUS_TRANSFERRED,
                'closed_at' => $date,
                'closed_balance' => $remaining,
                'closed_reason' => $data['reason'] ?? null,
                'remaining_balance' => 0,
                'transferred_to_id' => $new->id,
            ]);

            return $new;
        });

        return response()->json([
            'message' => 'نُقل المتبقي إلى السائق الجديد.',
            'data' => $new->load('employee:id,name'),
        ], 201);
    }

    /**
     * POST /api/salary-advances/{salaryAdvance}/reopen — undo a stop or a transfer.
     *
     * A transfer can only be undone while the advance it made has collected nothing: once a sheet
     * has taken an instalment from the new driver, that month has to be reopened first.
     */
    public function reopen(Request $request, SalaryAdvance $salaryAdvance): JsonResponse
    {
        if (! $request->user()->can('salary_advances.edit')) {
            return response()->json(['message' => 'غير مصرح لك بتعديل السلف.'], 403);
        }

        DB::transaction(function () use ($salaryAdvance) {
            $advance = SalaryAdvance::whereKey($salaryAdvance->id)->lockForUpdate()->firstOrFail();

            if (! in_array($advance->status, [SalaryAdvance::STATUS_STOPPED, SalaryAdvance::STATUS_TRANSFERRED], true)) {
                throw ValidationException::withMessages(['status' => 'هذه السلفة ليست موقوفة ولا منقولة.']);
            }

            if ($advance->status === SalaryAdvance::STATUS_TRANSFERRED && $advance->transferred_to_id) {
                $moved = SalaryAdvance::whereKey($advance->transferred_to_id)->lockForUpdate()->first();
                if ($moved) {
                    $collected = AdvanceDeduction::where('salary_advance_id', $moved->id)->exists() || (int) $moved->paid_installments > 0;
                    if ($collected) {
                        $name = Employee::find($moved->employee_id)?->name ?? '—';
                        throw ValidationException::withMessages(['status' => "خُصم من {$name} قسط من السلفة المنقولة في شهر معتمد — فكّ اعتماد ذلك الشهر أولاً، ثم تراجع عن النقل."]);
                    }
                    if ($moved->status !== 'active') {
                        throw ValidationException::withMessages(['status' => 'السلفة المنقولة أُوقفت أو نُقلت بدورها — تراجع عنها أولاً.']);
                    }
                    $moved->delete();
                }
            }

            $advance->update([
                'status' => 'active',
                'remaining_balance' => $advance->closed_balance,
                'closed_at' => null,
                'closed_balance' => null,
                'closed_reason' => null,
                'transferred_to_id' => null,
            ]);
        });

        return response()->json([
            'message' => 'أُعيدت السلفة فعّالة كما كانت.',
            'data' => $salaryAdvance->fresh(),
        ]);
    }

    private function mustBeActive(SalaryAdvance $advance): void
    {
        if ($advance->status !== 'active' || (float) $advance->remaining_balance <= 0) {
            throw ValidationException::withMessages(['status' => 'السلفة ليست فعّالة — لا متبقي عليها يُوقف أو يُنقل.']);
        }
    }
}
