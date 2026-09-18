<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Vehicle;
use App\Services\ContractScopeService;
use App\Services\DailyLogWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DailyLogController extends Controller
{
    private const STATUSES = DailyLogWriter::STATUSES;

    /** What pay or billing is worked out from: closed to edits once the day's month is approved. */
    private const FROZEN_AFTER_APPROVAL = [
        'orders_count', 'orders_online', 'orders_cash', 'rejected_orders_count', 'driver_status',
        'online_hours', 'ontime_rate', 'late_login', 'early_logout', 'is_valid', 'shift_valid', 'zone', 'notes',
    ];

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

        if ($locked = DailyLogWriter::lockedMonth($companyId, (int) $validated['contract_id'], $validated['log_date'])) {
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

        if ($unzoned = DailyLogWriter::unzonedOrdersBlock($contract, $vehicle->vehicle_type_id, $validated)) {
            return response()->json(['message' => $unzoned, 'errors' => ['zone' => [$unzoned]]], 422);
        }

        $attributes = array_merge($validated, [
            'orders_online' => (int) ($validated['orders_online'] ?? 0),
            'orders_cash' => (int) ($validated['orders_cash'] ?? 0),
            'cash_collected' => (float) ($validated['cash_collected'] ?? 0),
            'driver_status' => DailyLogWriter::deriveStatus($validated),
            'is_valid' => self::deriveValidity($contract, $validated),
        ]);

        $existed = $this->dayExists($attributes);
        $log = DailyLogWriter::write($companyId, $request->user()->id, $attributes);

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
            $lockedMonths[$key] ??= DailyLogWriter::lockedMonth($companyId, (int) $contractId, $logDate);
            if ($lockedMonths[$key]) {
                return response()->json(['message' => $lockedMonths[$key]], 422);
            }
        }

        $contractIds = array_unique(array_filter(array_column($logs, 'contract_id')));
        $contractsMap = Contract::whereIn('id', $contractIds)->get()->keyBy('id');
        $vehicleTypes = Vehicle::whereIn('id', array_unique(array_filter(array_column($logs, 'vehicle_id'))))
            ->pluck('vehicle_type_id', 'id');

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

            if ($contract && ($unzoned = DailyLogWriter::unzonedOrdersBlock($contract, $vehicleTypes[(int) $vehicleId] ?? null, $logData))) {
                $skipped[] = [
                    'log_date' => $logDate,
                    'employee_id' => $employeeId,
                    'contract_id' => $contractId,
                    'reason' => 'orders_without_zone',
                    'message' => $unzoned,
                ];

                continue;
            }

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
                'driver_status' => DailyLogWriter::deriveStatus($logData),
            ];
            // The grid decides validity itself and says so; a row that does not is valid.
            $attributes['is_valid'] = isset($logData['is_valid']) ? (bool) $logData['is_valid'] : true;
            $attributes['shift_valid'] = $attributes['is_valid'];

            $log = DailyLogWriter::write($companyId, $userId, $attributes);

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
                && ($blocked = DailyLogWriter::settledCashBlocks($dailyLog, (float) $request->input('cash_collected')))) {
                $validator->errors()->add('cash_collected', $blocked);
            }

            // Judged only when the edit moves what the client is billed on: a day that already
            // carries unzoned orders stays editable for everything else, it is a NEW one that is
            // refused.
            if ($dailyLog->contract && self::changedFields($request, $dailyLog, ['orders_count', 'zone', 'notes']) !== []) {
                $unzoned = DailyLogWriter::unzonedOrdersBlock($dailyLog->contract, $dailyLog->vehicle?->vehicle_type_id, [
                    'orders_count' => $request->has('orders_count') ? $request->input('orders_count') : $dailyLog->orders_count,
                    'zone' => $request->has('zone') ? $request->input('zone') : $dailyLog->zone,
                    'notes' => $request->has('notes') ? $request->input('notes') : $dailyLog->notes,
                ]);
                if ($unzoned) {
                    $validator->errors()->add('zone', $unzoned);
                }
            }
        });

        $validated = $validator->validate();

        // An approved month is frozen for whatever pay or billing is worked out from. The check
        // sat on creating and on the month grid only, so the same day could still be rewritten —
        // or deleted — from the list and from the driver's file. What feeds neither (cash handed
        // over, the odometer) stays correctable after the month is closed.
        $frozen = self::changedFields($request, $dailyLog, self::FROZEN_AFTER_APPROVAL);
        if ($frozen !== []
            && ($locked = DailyLogWriter::lockedMonth($this->currentCompanyId(), (int) $dailyLog->contract_id, (string) $dailyLog->log_date))) {
            return response()->json(['message' => $locked, 'errors' => [$frozen[0] => [$locked]]], 422);
        }

        if (isset($validated['cash_collected'])) {
            $validated['cash_pending'] = max(0, (float) $validated['cash_collected'] - (float) $dailyLog->cash_settled);
        }

        $merged = array_merge($dailyLog->only(['orders_count', 'rejected_orders_count', 'cash_collected', 'driver_status', 'online_hours', 'ontime_rate', 'late_login', 'early_logout']), $validated);

        if (array_key_exists('orders_count', $validated) || array_key_exists('driver_status', $validated)
            || array_key_exists('rejected_orders_count', $validated) || array_key_exists('cash_collected', $validated)) {
            $validated['driver_status'] = DailyLogWriter::deriveStatus($merged);
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

        if ($locked = DailyLogWriter::lockedMonth($this->currentCompanyId(), (int) $dailyLog->contract_id, (string) $dailyLog->log_date)) {
            return response()->json(['message' => $locked], 422);
        }

        $dailyLog->delete();

        return response()->json(['message' => 'Log deleted.']);
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
     * Which of the named fields a request would actually change on a saved day. The forms send
     * every field back whether it was touched or not, so presence says nothing; the value does.
     *
     * @param  array<int, string>  $fields
     * @return array<int, string>
     */
    private static function changedFields(Request $request, DailyLog $log, array $fields): array
    {
        $normalise = function (string $field, mixed $value): mixed {
            return match (true) {
                in_array($field, ['orders_count', 'orders_online', 'orders_cash', 'rejected_orders_count'], true) => (int) $value,
                in_array($field, ['online_hours', 'ontime_rate'], true) => $value === null || $value === '' ? null : round((float) $value, 2),
                in_array($field, ['late_login', 'early_logout', 'is_valid', 'shift_valid'], true) => filter_var($value, FILTER_VALIDATE_BOOLEAN),
                default => $value === null || trim((string) $value) === '' ? null : trim((string) $value),
            };
        };

        return array_values(array_filter(
            $fields,
            fn (string $field) => $request->has($field)
                && $normalise($field, $request->input($field)) !== $normalise($field, $log->getAttribute($field))
        ));
    }
}
