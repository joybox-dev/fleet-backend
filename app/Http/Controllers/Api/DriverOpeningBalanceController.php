<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsolidatedPayrollRun;
use App\Models\DriverOpeningBalance;
use App\Models\Employee;
use App\Services\PayrollBalanceService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The drivers' opening balances: what each one and the company owed each other before his first
 * approved month here. The figure is one line of the running account (PayrollBalanceService) — it
 * changes what a month's payment form suggests, never what a sheet says he earned.
 */
class DriverOpeningBalanceController extends Controller
{
    /**
     * GET /api/payroll/opening-balances
     *
     * Every driver with the figure he was entered with, if any, and where his account stands today.
     * Somebody who is no longer a driver — or no longer employed — stays listed while he has a
     * figure or a balance, because that is exactly the man whose money gets forgotten.
     */
    public function index(): JsonResponse
    {
        $companyId = $this->currentCompanyId();

        $declared = DriverOpeningBalance::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->with(['createdBy:id,name', 'updatedBy:id,name'])
            ->get()
            ->keyBy('employee_id');

        $standing = PayrollBalanceService::openingBalances($companyId);
        $withMoney = array_keys(array_filter($standing, fn ($entry) => abs($entry['balance']) >= 0.0005));

        $employees = Employee::withoutGlobalScopes()->withTrashed()
            ->where('company_id', $companyId)
            ->where(function ($q) use ($declared, $withMoney) {
                $q->where(fn ($drivers) => $drivers->where('role_category', 'driver')->whereNull('deleted_at'))
                    ->orWhereIn('id', $declared->keys()->merge($withMoney)->unique()->values());
            })
            ->orderBy('name')
            ->get(['id', 'name', 'name_ar', 'employee_number', 'civil_id', 'status', 'role_category', 'deleted_at']);

        $drivers = $employees->map(function (Employee $employee) use ($declared, $standing) {
            $entry = $standing[$employee->id] ?? null;

            return [
                'employee_id' => $employee->id,
                'employee_number' => $employee->employee_number,
                'name' => $employee->name,
                'name_ar' => $employee->name_ar,
                'civil_id' => $employee->civil_id,
                'status' => $employee->deleted_at ? 'deleted' : $employee->status,
                'is_driver' => $employee->role_category === 'driver',
                'opening' => $declared->get($employee->id)?->toRow(),
                'current_balance' => round((float) ($entry['balance'] ?? 0), 3),
                'current_through' => $entry['from'] ?? null,
            ];
        })->values();

        $amounts = $declared->map(fn (DriverOpeningBalance $row) => round((float) $row->amount, 3));
        $current = $drivers->pluck('current_balance');

        return response()->json([
            'drivers' => $drivers,
            'totals' => [
                'drivers' => $drivers->count(),
                'declared_count' => $amounts->count(),
                'owed_by_drivers' => round(abs($amounts->filter(fn ($a) => $a < 0)->sum()), 3),
                'owed_to_drivers' => round($amounts->filter(fn ($a) => $a > 0)->sum(), 3),
                'net' => round($amounts->sum(), 3),
                'current_owed_by_drivers' => round(abs($current->filter(fn ($b) => $b < 0)->sum()), 3),
                'current_owed_to_drivers' => round($current->filter(fn ($b) => $b > 0)->sum(), 3),
            ],
            'default_date' => $this->defaultDate($companyId),
        ]);
    }

    /**
     * POST /api/payroll/opening-balances
     *
     * A driver has one opening figure. A second one for the same man is refused rather than added
     * to the first: two people entering the same debt would otherwise double it in silence.
     */
    public function store(Request $request): JsonResponse
    {
        $companyId = $this->currentCompanyId();
        $data = $this->validated($request, true);

        $employee = Employee::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->find($data['employee_id']);
        if (! $employee) {
            throw ValidationException::withMessages(['employee_id' => 'السائق غير موجود في هذه الشركة.']);
        }

        $exists = DriverOpeningBalance::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('employee_id', $employee->id)
            ->exists();
        if ($exists) {
            return response()->json([
                'message' => "للسائق {$employee->name} رصيد أول المدة مسجَّل — عدِّله بدل إضافة رصيد ثانٍ.",
            ], 422);
        }

        $balance = DriverOpeningBalance::create([
            'company_id' => $companyId,
            'employee_id' => $employee->id,
            'amount' => $data['amount'],
            'balance_date' => $data['balance_date'],
            'notes' => $data['notes'],
            'created_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'تم تسجيل رصيد أول المدة.',
            'opening' => $balance->load(['createdBy:id,name', 'updatedBy:id,name'])->toRow(),
        ], 201);
    }

    /**
     * PUT /api/payroll/opening-balances/{openingBalance}
     */
    public function update(Request $request, DriverOpeningBalance $openingBalance): JsonResponse
    {
        $data = $this->validated($request, false);

        $openingBalance->update([
            'amount' => $data['amount'],
            'balance_date' => $data['balance_date'],
            'notes' => $data['notes'],
            'updated_by' => $request->user()?->id,
        ]);

        return response()->json([
            'message' => 'تم تعديل رصيد أول المدة.',
            'opening' => $openingBalance->load(['createdBy:id,name', 'updatedBy:id,name'])->toRow(),
        ]);
    }

    /**
     * DELETE /api/payroll/opening-balances/{openingBalance}
     */
    public function destroy(DriverOpeningBalance $openingBalance): JsonResponse
    {
        $openingBalance->delete();

        return response()->json(['message' => 'تم حذف رصيد أول المدة — عاد حساب السائق يبدأ من صفر.']);
    }

    /**
     * @return array{employee_id?: int, amount: float, balance_date: string, notes: ?string}
     */
    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'employee_id' => [$creating ? 'required' : 'prohibited', 'integer'],
            'amount' => 'required|numeric|between:-1000000,1000000',
            'balance_date' => 'required|date|before_or_equal:today',
            'notes' => 'nullable|string|max:500',
        ], [
            'employee_id.required' => 'اختر السائق.',
            'amount.required' => 'اكتب مبلغ الرصيد.',
            'amount.numeric' => 'المبلغ يجب أن يكون رقماً — بالسالب إن كان على السائق.',
            'amount.between' => 'المبلغ خارج الحدود المعقولة — راجعه.',
            'balance_date.required' => 'اكتب تاريخ الرصيد.',
            'balance_date.date' => 'تاريخ الرصيد غير صحيح.',
            'balance_date.before_or_equal' => 'تاريخ الرصيد لا يكون في المستقبل.',
            'notes.max' => 'الملاحظة أطول من 500 حرف.',
        ]);

        $amount = round((float) $data['amount'], 3);
        if (abs($amount) < 0.0005) {
            throw ValidationException::withMessages([
                'amount' => 'الرصيد صفر لا يُسجَّل — اكتب مبلغاً، أو احذف الرصيد إن لم يعد له لزوم.',
            ]);
        }

        return [
            ...($creating ? ['employee_id' => (int) $data['employee_id']] : []),
            'amount' => $amount,
            'balance_date' => Carbon::parse($data['balance_date'])->toDateString(),
            'notes' => isset($data['notes']) && trim($data['notes']) !== '' ? trim($data['notes']) : null,
        ];
    }

    /**
     * The day a new figure is dated by default: the first day of the earliest month approved here —
     * an opening balance is what stood before that — or of this month when nothing is approved yet.
     */
    private function defaultDate(int $companyId): string
    {
        $first = ConsolidatedPayrollRun::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'approved')
            ->orderBy('year')
            ->orderBy('month')
            ->first(['year', 'month']);

        return $first
            ? Carbon::create($first->year, $first->month, 1)->toDateString()
            : Carbon::now()->startOfMonth()->toDateString();
    }
}
