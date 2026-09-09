<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConsolidatedPayrollRun;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\ContractPayrollRun;
use App\Models\DailyLog;
use App\Models\Vehicle;
use App\Services\ContractScopeService;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DailyLogController extends Controller
{
    private const STATUSES = ['working', 'absent', 'unexcused_absent', 'paid_leave', 'unpaid_leave', 'sick_leave', 'holiday'];

    /**
     * GET /api/daily-logs
     * List logs with filters: date range, employee, vehicle, contract.
     */
    public function index(Request $request): JsonResponse
    {
        $allowedIds = ContractScopeService::getAllocatedContractIds();
        $perPage = min(max($request->integer('per_page', 50), 5), 100);

        $logs = DailyLog::with(['employee:id,name,name_ar,employee_number', 'vehicle:id,plate_number,make,model', 'contract:id,name,payment_type'])
            ->when($allowedIds !== null, fn ($q) => $q->whereIn('contract_id', $allowedIds))
            ->when($request->employee_id, fn ($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->vehicle_id, fn ($q) => $q->where('vehicle_id', $request->vehicle_id))
            ->when($request->contract_id, fn ($q) => $q->where('contract_id', $request->contract_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('log_date', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('log_date', '<=', $request->date_to))
            ->when($request->search, function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($query) use ($search) {
                    $query->whereHas('employee', fn ($el) => $el->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('vehicle', fn ($vl) => $vl->where('plate_number', 'like', "%{$search}%"))
                        ->orWhereHas('contract', fn ($cl) => $cl->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('log_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json($logs);
    }

    /**
     * POST /api/daily-logs
     * Create or replace one driver's day on one contract. Allows backdating.
     */
    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('daily_logs.create')) {
            return response()->json(['message' => 'غير مصرح لك بإضافة سجلات تشغيل.'], 403);
        }

        $companyId = $this->currentCompanyId();

        $validator = \Validator::make($request->all(), [
            'employee_id' => [
                'required',
                Rule::exists('employees', 'id')
                    ->where('company_id', $companyId)
                    ->where('role_category', 'driver'),
            ],
            'vehicle_id' => ['required', Rule::exists('vehicles', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'contract_id' => ['required', Rule::exists('contracts', 'id')->where('company_id', $companyId)->whereNull('deleted_at')],
            'log_date' => 'required|date',
            'orders_count' => 'required|integer|min:0',
            'orders_online' => 'nullable|integer|min:0',
            'orders_cash' => 'nullable|integer|min:0',
            'rejected_orders_count' => 'nullable|integer|min:0',
            'cash_collected' => 'nullable|numeric|min:0',
            'odometer_start' => 'nullable|integer|min:0',
            'odometer_end' => 'nullable|integer|min:0|gte:odometer_start',
            'odometer_photo_path' => 'nullable|string',
            'notes' => 'nullable|string|max:500',
            'online_hours' => 'nullable|numeric|min:0',
            'ontime_rate' => 'nullable|numeric|min:0|max:100',
            'avg_delivery_time' => 'nullable|integer|min:0',
            'late_login' => 'nullable|boolean',
            'early_logout' => 'nullable|boolean',
            'is_valid' => 'nullable|boolean',
            'shift_valid' => 'nullable|boolean',
            'zone' => 'nullable|string|max:255',
            'driver_status' => ['nullable', Rule::in(self::STATUSES)],
        ]);

        $validator->after(function ($validator) use ($request) {
            // A zero is what the form sends for “no reading taken”, and `filled` counts that as a
            // reading — so it demanded a photo of an odometer nobody read.
            if ((float) $request->input('odometer_end') > 0 && ! $request->filled('odometer_photo_path')) {
                $validator->errors()->add('odometer_photo_path', 'يجب رفع صورة العداد الحية لتأكيد القراءة.');
            }
        });

        $validated = $validator->validate();

        if ($locked = $this->lockedMonth($companyId, (int) $validated['contract_id'], $validated['log_date'])) {
            return response()->json(['message' => $locked], 422);
        }

        if (! $this->isAssigned((int) $validated['employee_id'], (int) $validated['contract_id'], $validated['log_date'])) {
            return response()->json([
                'message' => 'لا يمكن إدخال أو تعديل سجل يومي للسائق في تاريخ خارج فترة تعيينه الرسمية على هذا العقد.',
                'errors' => ['log_date' => ['السائق غير معين على هذا العقد في هذا التاريخ.']],
            ], 422);
        }

        $contract = Contract::findOrFail($validated['contract_id']);
        $vehicle = Vehicle::findOrFail($validated['vehicle_id']);

        if ($contract->vehicle_type_id !== null && $vehicle->vehicle_type_id !== null
            && $contract->vehicle_type_id !== $vehicle->vehicle_type_id) {
            return response()->json([
                'message' => 'فئة هذه المركبة غير مدعومة في هذا العقد.',
                'errors' => ['contract_id' => ['نوع المركبة غير متوافق مع العقد.']],
            ], 422);
        }

        $attributes = array_merge($validated, [
            'orders_online' => (int) ($validated['orders_online'] ?? 0),
            'orders_cash' => (int) ($validated['orders_cash'] ?? 0),
            'cash_collected' => (float) ($validated['cash_collected'] ?? 0),
            'driver_status' => self::deriveStatus($validated),
            'is_valid' => self::deriveValidity($contract, $validated),
        ]);

        $existed = $this->dayExists($attributes);
        $log = $this->writeDay($companyId, $request->user()->id, $attributes);

        if (is_string($log)) {
            return response()->json(['message' => $log, 'errors' => ['cash_collected' => [$log]]], 422);
        }

        return response()->json($log->fresh(['employee:id,name', 'vehicle:id,plate_number']), $existed ? 200 : 201);
    }

    /**
     * POST /api/daily-logs/bulk
     * A month of days in one request. Rows this request refuses to write are named in the
     * response rather than dropped: a driver's whole month could be edited, reported as saved, and
     * left untouched.
     */
    public function bulkStore(Request $request): JsonResponse
    {
        if (! $request->user()->can('daily_logs.create')) {
            return response()->json(['message' => 'غير مصرح لك بإضافة سجلات تشغيل.'], 403);
        }

        $logs = $request->input('logs', []);
        if (! is_array($logs) || empty($logs)) {
            return response()->json(['message' => 'قائمة السجلات فارغة.'], 422);
        }

        $companyId = $this->currentCompanyId();
        $userId = $request->user()->id;

        // Checked per row, not on row zero: a payload can span two months, and a day in an
        // approved month must be refused wherever it sits in the list.
        $lockedMonths = [];
        foreach ($logs as $logData) {
            $contractId = $logData['contract_id'] ?? null;
            $logDate = $logData['log_date'] ?? null;
            if (! $contractId || ! $logDate) {
                continue;
            }
            $key = substr((string) $logDate, 0, 7).'|'.$contractId;
            $lockedMonths[$key] ??= $this->lockedMonth($companyId, (int) $contractId, $logDate);
            if ($lockedMonths[$key]) {
                return response()->json(['message' => $lockedMonths[$key]], 422);
            }
        }

        $contractIds = array_unique(array_filter(array_column($logs, 'contract_id')));
        $contractsMap = Contract::whereIn('id', $contractIds)->get()->keyBy('id');

        $savedLogs = [];
        $skipped = [];

        foreach ($logs as $logData) {
            $employeeId = $logData['employee_id'] ?? null;
            $logDate = $logData['log_date'] ?? null;
            $contractId = $logData['contract_id'] ?? null;
            $vehicleId = $logData['vehicle_id'] ?? null;

            // A day with no vehicle used to be written against vehicle #1 — whatever that was.
            if (! $employeeId || ! $logDate || ! $contractId || ! $vehicleId) {
                $skipped[] = [
                    'log_date' => $logDate,
                    'employee_id' => $employeeId,
                    'reason' => 'incomplete',
                    'message' => 'السجل ناقص بيانات أساسية (الموظف أو التاريخ أو العقد أو المركبة).',
                ];

                continue;
            }

            if (! $this->isAssigned((int) $employeeId, (int) $contractId, $logDate)) {
                $skipped[] = [
                    'log_date' => $logDate,
                    'employee_id' => $employeeId,
                    'contract_id' => $contractId,
                    'reason' => 'not_assigned',
                    'message' => 'السائق غير معيّن على هذا العقد في هذا التاريخ.',
                ];

                continue;
            }

            $contract = $contractsMap->get($contractId);
            $ordersCount = (int) ($logData['orders_count'] ?? 0);
            $ordersCash = (int) ($logData['orders_cash'] ?? 0);

            $attributes = [
                'employee_id' => (int) $employeeId,
                'vehicle_id' => (int) $vehicleId,
                'contract_id' => (int) $contractId,
                'log_date' => $logDate,
                'orders_count' => $ordersCount,
                'orders_online' => max(0, $ordersCount - $ordersCash),
                'orders_cash' => $ordersCash,
                'rejected_orders_count' => (int) ($logData['rejected_orders_count'] ?? 0),
                'cash_collected' => (float) ($logData['cash_collected'] ?? 0),
                'online_hours' => (float) ($logData['online_hours'] ?? 10),
                'ontime_rate' => isset($logData['ontime_rate']) ? (float) $logData['ontime_rate'] : null,
                'zone' => $logData['zone'] ?? null,
                'late_login' => (bool) ($logData['late_login'] ?? false),
                'early_logout' => (bool) ($logData['early_logout'] ?? false),
                'notes' => $logData['notes'] ?? null,
                'driver_status' => self::deriveStatus($logData),
            ];
            // The grid decides validity itself and says so; a row that does not is valid.
            $attributes['is_valid'] = isset($logData['is_valid']) ? (bool) $logData['is_valid'] : true;
            $attributes['shift_valid'] = $attributes['is_valid'];

            $log = $this->writeDay($companyId, $userId, $attributes);

            if (is_string($log)) {
                $skipped[] = [
                    'log_date' => $logDate,
                    'employee_id' => $employeeId,
                    'contract_id' => $contractId,
                    'reason' => 'cash_already_settled',
                    'message' => $log,
                ];

                continue;
            }

            $savedLogs[] = $log;
        }

        $skippedCount = count($skipped);
        $savedCount = count($savedLogs);

        // Dates are the thing the user can act on, so name them rather than only counting.
        $skippedDates = collect($skipped)
            ->pluck('log_date')
            ->filter()
            ->map(fn ($d) => substr((string) $d, 0, 10))
            ->unique()
            ->values();

        return response()->json([
            'message' => $skippedCount === 0
                ? 'تم حفظ السجلات بنجاح.'
                : "تم حفظ {$savedCount} سجل، ولم يتم حفظ {$skippedCount} سجل.",
            'count' => $savedCount,
            'skipped_count' => $skippedCount,
            'skipped' => $skipped,
            'skipped_dates' => $skippedDates,
            'partial' => $skippedCount > 0,
        ]);
    }

    /**
     * GET /api/daily-logs/{id}
     */
    public function show(DailyLog $dailyLog): JsonResponse
    {
        return response()->json($dailyLog->load(['employee', 'vehicle', 'contract', 'createdBy:id,name']));
    }

    /**
     * PUT /api/daily-logs/{id}
     */
    public function update(Request $request, DailyLog $dailyLog): JsonResponse
    {
        if (! $request->user()->can('daily_logs.edit')) {
            return response()->json(['message' => 'غير مصرح لك بتعديل سجلات التشغيل.'], 403);
        }

        $validator = \Validator::make($request->all(), [
            'orders_count' => 'sometimes|integer|min:0',
            'orders_online' => 'sometimes|integer|min:0',
            'orders_cash' => 'sometimes|integer|min:0',
            'rejected_orders_count' => 'sometimes|integer|min:0',
            'cash_collected' => 'sometimes|numeric|min:0',
            'odometer_start' => 'nullable|integer|min:0',
            'odometer_end' => 'nullable|integer|min:0',
            'odometer_photo_path' => 'nullable|string',
            'notes' => 'nullable|string|max:500',
            'online_hours' => 'nullable|numeric|min:0',
            'ontime_rate' => 'nullable|numeric|min:0|max:100',
            'avg_delivery_time' => 'nullable|integer|min:0',
            'late_login' => 'nullable|boolean',
            'early_logout' => 'nullable|boolean',
            'is_valid' => 'nullable|boolean',
            'shift_valid' => 'nullable|boolean',
            'zone' => 'nullable|string|max:255',
            'driver_status' => ['nullable', Rule::in(self::STATUSES)],
        ]);

        $validator->after(function ($validator) use ($request, $dailyLog) {
            $start = $request->has('odometer_start') ? $request->input('odometer_start') : $dailyLog->odometer_start;
            $end = $request->has('odometer_end') ? $request->input('odometer_end') : $dailyLog->odometer_end;

            if ($start !== null && $end !== null && $end < $start) {
                $validator->errors()->add('odometer_end', 'قراءة عداد النهاية يجب أن تكون أكبر من أو تساوي قراءة البداية.');
            }

            $hasEnd = $request->has('odometer_end')
                ? (float) $request->input('odometer_end') > 0
                : (float) $dailyLog->odometer_end > 0;
            $hasPhoto = $request->has('odometer_photo_path') ? $request->filled('odometer_photo_path') : ! empty($dailyLog->odometer_photo_path);

            if ($hasEnd && ! $hasPhoto) {
                $validator->errors()->add('odometer_photo_path', 'يجب رفع صورة العداد الحية لتأكيد القراءة.');
            }

            if ($request->has('cash_collected')
                && ($blocked = self::settledCashBlocks($dailyLog, (float) $request->input('cash_collected')))) {
                $validator->errors()->add('cash_collected', $blocked);
            }
        });

        $validated = $validator->validate();

        if (isset($validated['cash_collected'])) {
            $validated['cash_pending'] = max(0, (float) $validated['cash_collected'] - (float) $dailyLog->cash_settled);
        }

        $merged = array_merge($dailyLog->only(['orders_count', 'rejected_orders_count', 'cash_collected', 'driver_status', 'online_hours', 'ontime_rate', 'late_login', 'early_logout']), $validated);

        if (array_key_exists('orders_count', $validated) || array_key_exists('driver_status', $validated)
            || array_key_exists('rejected_orders_count', $validated) || array_key_exists('cash_collected', $validated)) {
            $validated['driver_status'] = self::deriveStatus($merged);
        }

        // Respect a manual override; otherwise judge the day again from what it now holds.
        if (! array_key_exists('is_valid', $validated) && $dailyLog->contract) {
            $validated['is_valid'] = self::deriveValidity($dailyLog->contract, $merged);
        }

        $dailyLog->update($validated);

        return response()->json($dailyLog->fresh());
    }

    /**
     * DELETE /api/daily-logs/{id}
     */
    public function destroy(Request $request, DailyLog $dailyLog): JsonResponse
    {
        if (! $request->user()->can('daily_logs.delete')) {
            return response()->json(['message' => 'غير مصرح لك بحذف سجلات التشغيل.'], 403);
        }

        $dailyLog->delete();

        return response()->json(['message' => 'Log deleted.']);
    }

    /**
     * Why a day in this month may not be written, or null when it may.
     *
     * The consolidated month is the only lock that covers every contract at once, and it is the
     * one that matters: once approved it is frozen and serves a snapshot forever, so a log added
     * afterwards is earnings the driver is never paid for. The contract-level check only sees the
     * one contract named.
     */
    private function lockedMonth(int $companyId, int $contractId, string $logDate): ?string
    {
        $time = strtotime($logDate);
        $year = (int) date('Y', $time);
        $month = (int) date('n', $time);

        $consolidated = ConsolidatedPayrollRun::where('company_id', $companyId)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'approved')
            ->exists();

        if ($consolidated) {
            return 'تم اعتماد كشف الرواتب المجمّع لهذا الشهر ولا يمكن تعديل السجلات اليومية.';
        }

        $contractLocked = ContractPayrollRun::where('company_id', $companyId)
            ->where('contract_id', $contractId)
            ->where('year', $year)
            ->where('month', $month)
            ->where('status', 'approved')
            ->exists();

        return $contractLocked
            ? 'تم اعتماد كشف رواتب هذا العقد لهذا الشهر ولا يمكن تعديل السجلات اليومية.'
            : null;
    }

    private function isAssigned(int $employeeId, int $contractId, string $logDate): bool
    {
        return ContractAssignment::withoutGlobalScopes()
            ->where('employee_id', $employeeId)
            ->where('contract_id', $contractId)
            ->whereDate('start_date', '<=', $logDate)
            ->where(function ($q) use ($logDate) {
                $q->whereNull('end_date')->orWhereDate('end_date', '>=', $logDate);
            })
            ->exists();
    }

    /**
     * A day with activity on it is a working day whatever the form said; an empty day keeps the
     * status it was given, and is unpaid leave when it was given none.
     *
     * @param  array<string, mixed>  $data
     */
    private static function deriveStatus(array $data): string
    {
        $orders = (int) ($data['orders_count'] ?? 0);
        $rejected = (int) ($data['rejected_orders_count'] ?? 0);
        $cash = (float) ($data['cash_collected'] ?? 0);

        if ($orders > 0 || $rejected > 0 || $cash > 0) {
            return 'working';
        }

        $status = $data['driver_status'] ?? null;

        return $status && in_array($status, self::STATUSES, true) ? $status : 'unpaid_leave';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function deriveValidity(Contract $contract, array $data): bool
    {
        if (array_key_exists('is_valid', $data) && $data['is_valid'] !== null) {
            return (bool) $data['is_valid'];
        }

        if (! $contract->is_validity_enabled) {
            return true;
        }

        return (float) ($data['online_hours'] ?? 0) >= 10.0
            && (float) ($data['ontime_rate'] ?? 0) >= 90.0
            && (int) ($data['orders_count'] ?? 0) >= 2
            && ! (bool) ($data['late_login'] ?? false)
            && ! (bool) ($data['early_logout'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function dayExists(array $attributes): bool
    {
        return DailyLog::withoutGlobalScopes()
            ->where('employee_id', $attributes['employee_id'])
            ->where('contract_id', $attributes['contract_id'])
            ->where('log_date', $attributes['log_date'])
            ->exists();
    }

    /**
     * Write one driver's day on one contract, replacing whatever row that day already has.
     *
     * The day is matched on employee AND contract: matching on employee and date alone reached
     * across to whatever other contract the driver had worked that day and overwrote it. A
     * duplicate row left behind by an older bug is removed on the way. Cash already handed over
     * cannot be un-collected, so a day that has been settled refuses a lower collection.
     *
     * @param  array<string, mixed>  $attributes
     * @return DailyLog|string the row, or the message refusing it
     */
    private function writeDay(int $companyId, int $userId, array $attributes): DailyLog|string
    {
        $matching = DailyLog::withTrashed()->withoutGlobalScopes()
            ->where('employee_id', $attributes['employee_id'])
            ->where('contract_id', $attributes['contract_id'])
            ->where('log_date', $attributes['log_date'])
            ->orderBy('id')
            ->get();

        foreach ($matching->slice(1) as $duplicate) {
            $duplicate->forceDelete();
        }

        $cashCollected = (float) ($attributes['cash_collected'] ?? 0);
        $existing = $matching->first();

        if (! $existing) {
            try {
                return DailyLog::create(array_merge($attributes, [
                    'company_id' => $companyId,
                    'created_by' => $userId,
                    'cash_settled' => 0,
                    'cash_pending' => $cashCollected,
                ]));
            } catch (QueryException $e) {
                // Written by a concurrent request in the meantime: only this contract's row can be
                // the one that clashed, so take it and update it.
                $existing = DailyLog::withTrashed()->withoutGlobalScopes()
                    ->where('employee_id', $attributes['employee_id'])
                    ->where('contract_id', $attributes['contract_id'])
                    ->where('log_date', $attributes['log_date'])
                    ->first();
                if (! $existing) {
                    throw $e;
                }
            }
        }

        if ($existing->trashed()) {
            $existing->restore();
        }

        if ($blocked = self::settledCashBlocks($existing, $cashCollected)) {
            return $blocked;
        }

        $existing->update(array_merge($attributes, [
            'company_id' => $companyId,
            'cash_pending' => max(0, $cashCollected - (float) ($existing->cash_settled ?? 0)),
        ]));

        return $existing;
    }

    /**
     * Cash already handed to the accountant cannot be un-collected. Dropping a day's collection
     * below what was settled on it leaves the books holding money the driver never took in.
     * A day already in that state may still be raised toward its settled figure, so a broken row
     * can be repaired; only making it worse is refused.
     */
    private static function settledCashBlocks(?DailyLog $log, float $cashCollected): ?string
    {
        if (! $log) {
            return null;
        }

        $settled = round((float) $log->cash_settled, 3);
        $current = round((float) $log->cash_collected, 3);
        $next = round($cashCollected, 3);

        if ($settled <= 0 || $next >= $settled || $next >= $current) {
            return null;
        }

        return sprintf(
            'هذا اليوم مُسوّى بمبلغ %s د.ك — لا يمكن تخفيض الكاش المجمّع تحت هذا المبلغ. عدّل التسوية أولاً.',
            number_format($settled, 3)
        );
    }
}
