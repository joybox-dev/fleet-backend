<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\DailyLog;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use App\Services\ContractScopeService;

class DailyLogController extends Controller
{
    /**
     * GET /api/daily-logs
     * List logs with filters: date range, employee, vehicle, contract.
     */
    public function index(Request $request): JsonResponse
    {
        $allowedIds = ContractScopeService::getAllocatedContractIds();
        $perPage = min(max($request->integer('per_page', 50), 5), 100);

        $logs = DailyLog::with(['employee:id,name,name_ar,employee_number', 'vehicle:id,plate_number,make,model', 'contract:id,name,payment_type'])
            ->when($allowedIds !== null, fn($q) => $q->whereIn('contract_id', $allowedIds))
            ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->vehicle_id, fn($q) => $q->where('vehicle_id', $request->vehicle_id))
            ->when($request->contract_id, fn($q) => $q->where('contract_id', $request->contract_id))
            ->when($request->date_from, fn($q) => $q->whereDate('log_date', '>=', $request->date_from))
            ->when($request->date_to, fn($q) => $q->whereDate('log_date', '<=', $request->date_to))
            ->when($request->search, function ($q) use ($request) {
                $search = $request->search;
                $q->where(function ($query) use ($search) {
                    $query->whereHas('employee', fn($el) => $el->where('name', 'like', "%{$search}%"))
                          ->orWhereHas('vehicle', fn($vl) => $vl->where('plate_number', 'like', "%{$search}%"))
                          ->orWhereHas('contract', fn($cl) => $cl->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderByDesc('log_date')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json($logs);
    }

    /**
     * POST /api/daily-logs
     * Create a new daily log entry. Auto-calculates income from contract rate.
     * Allows backdating (flexible entry per meeting notes).
     */
    public function store(Request $request): JsonResponse
    {
        if (!$request->user()->can('daily_logs.create')) {
            return response()->json(['message' => 'غير مصرح لك بإضافة سجلات تشغيل.'], 403);
        }

        $companyId = app()->bound('current_company_id') ? app('current_company_id') : ($request->user()?->company_id ?? 1);

        // Auto-adjust orders if needed (e.g. zones contracts where they aren't collected separately)
        $total = (int) $request->input('orders_count', 0);

        if ($total === 0) {
            $request->merge([
                'orders_online' => 0,
                'orders_cash' => 0
            ]);
        } else {
            $contract = Contract::find($request->input('contract_id'));
            $isZones = false;
            if ($contract) {
                $pricing = $contract->driver_pricing_rules ?? [];
                foreach ($pricing as $rule) {
                    $method = $rule['payment_method'] ?? 'fixed';
                    if ($method === 'zone' || $method === 'zones' || $method === 'zones_tiers') {
                        $isZones = true;
                        break;
                    }
                }
            }

            if ($isZones) {
                $request->merge([
                    'orders_online' => $total,
                    'orders_cash' => 0
                ]);
            } else {
                $online = $request->input('orders_online');
                $cash = $request->input('orders_cash');
                if (($online === null || (int)$online === 0) && ($cash === null || (int)$cash === 0)) {
                    $request->merge([
                        'orders_online' => $total,
                        'orders_cash' => 0
                    ]);
                }
            }
        }

        $validator = \Validator::make($request->all(), [
            'employee_id'         => [
                'required',
                \Illuminate\Validation\Rule::exists('employees', 'id')
                    ->where('company_id', $companyId)
                    ->where('role_category', 'driver'),
            ],
            'vehicle_id'          => 'required|exists:vehicles,id',
            'contract_id'         => 'required|exists:contracts,id',
            'log_date'            => 'required|date',
            'orders_count'          => 'required|integer|min:0',
            'orders_online'         => 'nullable|integer|min:0',
            'orders_cash'           => 'nullable|integer|min:0',
            'rejected_orders_count' => 'nullable|integer|min:0',
            'cash_collected'        => 'nullable|numeric|min:0',
            'odometer_start'      => 'nullable|integer|min:0',
            'odometer_end'        => 'nullable|integer|min:0|gte:odometer_start',
            'odometer_photo_path' => 'nullable|string',
            'notes'               => 'nullable|string|max:500',
            'online_hours'        => 'nullable|numeric|min:0',
            'ontime_rate'         => 'nullable|numeric|min:0|max:100',
            'avg_delivery_time'   => 'nullable|integer|min:0',
            'late_login'          => 'nullable|boolean',
            'early_logout'        => 'nullable|boolean',
            'is_valid'            => 'nullable|boolean',
            'shift_valid'         => 'nullable|boolean',
            'zone'                => 'nullable|string|max:255',
        ]);

        $validator->after(function ($validator) use ($request) {
            $total = (int) $request->input('orders_count', 0);
            $online = (int) $request->input('orders_online', 0);
            $cash = (int) $request->input('orders_cash', 0);

            if (($online + $cash) !== $total) {
                $validator->errors()->add('orders_count', 'مجموع طلبات الكاش والأونلاين يجب أن يساوي عدد الطلبات الإجمالي.');
            }

            if ($request->filled('odometer_end') && !$request->filled('odometer_photo_path')) {
                $validator->errors()->add('odometer_photo_path', 'يجب رفع صورة العداد الحية لتأكيد القراءة.');
            }
        });

        $validated = $validator->validate();

        // Check Payroll Approval Lock
        $logTime = strtotime($validated['log_date']);
        $logYear = (int) date('Y', $logTime);
        $logMonth = (int) date('n', $logTime);
        $isPayrollLocked = \App\Models\PayrollRun::where('company_id', app('current_company_id') ?? 1)
            ->where('year', $logYear)
            ->where('month', $logMonth)
            ->where('status', 'approved')
            ->exists();

        if ($isPayrollLocked) {
            return response()->json(['message' => 'تم اعتماد رواتب هذا الشهر ولا يمكن تعديل السجلات اليومية.'], 422);
        }

        // Auto-update existing record if log already exists for same employee and date (matching vehicle, contract, or employee+date)
        $existingLog = DailyLog::withTrashed()->withoutGlobalScopes()
            ->where('employee_id', $validated['employee_id'])
            ->where('log_date', $validated['log_date'])
            ->where(function ($q) use ($validated) {
                $q->where('vehicle_id', $validated['vehicle_id'])
                  ->orWhere('contract_id', $validated['contract_id']);
            })
            ->first();

        if (!$existingLog) {
            $existingLog = DailyLog::withTrashed()->withoutGlobalScopes()
                ->where('employee_id', $validated['employee_id'])
                ->where('log_date', $validated['log_date'])
                ->first();
        }

        // Fetch contract to snapshot rate and auto-calculate income
        $contract = Contract::findOrFail($validated['contract_id']);
        $vehicle  = \App\Models\Vehicle::findOrFail($validated['vehicle_id']);

        if ($contract->vehicle_type_id !== null && $vehicle->vehicle_type_id !== null) {
            if ($contract->vehicle_type_id !== $vehicle->vehicle_type_id) {
                return response()->json([
                    'message' => 'فئة هذه المركبة غير مدعومة في هذا العقد.',
                    'errors' => ['contract_id' => ['نوع المركبة غير متوافق مع العقد.']]
                ], 422);
            }
        }

        $rate     = $contract->rate_per_order;
        $income   = $rate * $validated['orders_count'];

        // Auto-calculate daily validity if not explicitly passed
        $lateLogin = isset($validated['late_login']) ? (bool)$validated['late_login'] : false;
        $earlyLogout = isset($validated['early_logout']) ? (bool)$validated['early_logout'] : false;
        $onlineHours = isset($validated['online_hours']) ? (float)$validated['online_hours'] : 0.0;
        $ontimeRate = isset($validated['ontime_rate']) ? (float)$validated['ontime_rate'] : 0.0;
        $ordersCount = (int)$validated['orders_count'];
        $driverStatus = $validated['driver_status'] ?? ($ordersCount > 0 ? 'working' : 'working');

        if (isset($validated['is_valid'])) {
            $isValid = (bool)$validated['is_valid'];
        } else {
            if ($contract->is_validity_enabled) {
                $isValid = ($onlineHours >= 10.0) && ($ontimeRate >= 90.0) && ($ordersCount >= 2) && !$lateLogin && !$earlyLogout;
            } else {
                $isValid = true;
            }
        }

        $cashCollected = $validated['cash_collected'] ?? 0;

        if ($existingLog) {
            if ($existingLog->trashed()) {
                $existingLog->restore();
            }
            $settled = $existingLog->cash_settled ?? 0;
            $existingLog->update(array_merge($validated, [
                'company_id'     => $companyId,
                'rate_per_order' => $rate,
                'income_amount'  => $income,
                'orders_online'  => $validated['orders_online'] ?? $ordersCount,
                'orders_cash'    => $validated['orders_cash'] ?? 0,
                'cash_collected' => $cashCollected,
                'cash_pending'   => max(0, $cashCollected - $settled),
                'is_valid'       => $isValid,
                'driver_status'  => $driverStatus,
            ]));
            return response()->json($existingLog->fresh(['employee:id,name', 'vehicle:id,plate_number']), 200);
        }

        try {
            $log = DailyLog::create(array_merge($validated, [
                'company_id'      => $companyId,
                'created_by'      => $request->user()?->id ?? 1,
                'rate_per_order'  => $rate,
                'income_amount'   => $income,
                'orders_online'   => $validated['orders_online'] ?? 0,
                'orders_cash'     => $validated['orders_cash'] ?? 0,
                'cash_collected'  => $cashCollected,
                'cash_settled'    => 0,
                'cash_pending'    => $cashCollected,
                'is_valid'        => $isValid,
                'driver_status'  => $driverStatus,
            ]));
            return response()->json($log->load(['employee:id,name', 'vehicle:id,plate_number']), 201);
        } catch (\Illuminate\Database\QueryException $e) {
            $fallback = DailyLog::withTrashed()->withoutGlobalScopes()
                ->where('employee_id', $validated['employee_id'])
                ->where('log_date', $validated['log_date'])
                ->first();
            if (!$fallback) {
                $fallback = DailyLog::withTrashed()->withoutGlobalScopes()
                    ->where('employee_id', $validated['employee_id'])
                    ->where('vehicle_id', $validated['vehicle_id'])
                    ->where('log_date', $validated['log_date'])
                    ->first();
            }
            if ($fallback) {
                if ($fallback->trashed()) {
                    $fallback->restore();
                }
                $settled = $fallback->cash_settled ?? 0;
                $fallback->update(array_merge($validated, [
                    'company_id'     => $companyId,
                    'rate_per_order' => $rate,
                    'income_amount'  => $income,
                    'orders_online'  => $validated['orders_online'] ?? $ordersCount,
                    'orders_cash'    => $validated['orders_cash'] ?? 0,
                    'cash_collected' => $cashCollected,
                    'cash_pending'   => max(0, $cashCollected - $settled),
                    'is_valid'       => $isValid,
                    'driver_status'  => $driverStatus,
                ]));
                return response()->json($fallback->fresh(['employee:id,name', 'vehicle:id,plate_number']), 200);
            }
            throw $e;
        }
    }

    /**
     * POST /api/daily-logs/bulk
     * Batch save multiple daily logs in a single fast transaction.
     */
    public function bulkStore(Request $request): JsonResponse
    {
        if (!$request->user()->can('daily_logs.create')) {
            return response()->json(['message' => 'غير مصرح لك بإضافة سجلات تشغيل.'], 403);
        }

        $logs = $request->input('logs', []);
        if (!is_array($logs) || empty($logs)) {
            return response()->json(['message' => 'قائمة السجلات فارغة.'], 422);
        }

        // Check Payroll Approval Lock for the batch
        if (!empty($logs)) {
            $sampleDate = $logs[0]['log_date'] ?? null;
            if ($sampleDate) {
                $time = strtotime($sampleDate);
                $logYear = (int) date('Y', $time);
                $logMonth = (int) date('n', $time);
                $companyId = app()->bound('current_company_id') ? app('current_company_id') : ($request->user()?->company_id ?? 1);
                $isPayrollLocked = \App\Models\PayrollRun::where('company_id', $companyId)
                    ->where('year', $logYear)
                    ->where('month', $logMonth)
                    ->where('status', 'approved')
                    ->exists();

                if ($isPayrollLocked) {
                    return response()->json(['message' => 'تم اعتماد رواتب هذا الشهر ولا يمكن تعديل السجلات اليومية.'], 422);
                }
            }
        }

        $savedLogs = [];
        $contractIds = array_unique(array_filter(array_column($logs, 'contract_id')));
        $contractsMap = Contract::whereIn('id', $contractIds)->get()->keyBy('id');

        foreach ($logs as $logData) {
            $employeeId = $logData['employee_id'] ?? null;
            $logDate = $logData['log_date'] ?? null;
            $contractId = $logData['contract_id'] ?? null;
            if (!$employeeId || !$logDate || !$contractId) continue;

            $vehicleId = $logData['vehicle_id'] ?? 1;
            $ordersCount = (int) ($logData['orders_count'] ?? 0);
            $rejectedOrdersCount = (int) ($logData['rejected_orders_count'] ?? 0);
            $cashCollected = (float) ($logData['cash_collected'] ?? 0);
            $onlineHours = (float) ($logData['online_hours'] ?? 10);
            $zone = $logData['zone'] ?? null;
            $isValid = isset($logData['is_valid']) ? (bool) $logData['is_valid'] : true;
            $lateLogin = isset($logData['late_login']) ? (bool) $logData['late_login'] : false;
            $earlyLogout = isset($logData['early_logout']) ? (bool) $logData['early_logout'] : false;
            $driverStatus = $logData['driver_status'] ?? 'working';

            $contract = $contractsMap->get($contractId);
            $rate = $contract ? (float) $contract->rate_per_order : 0.0;
            $income = $rate * $ordersCount;

            $allMatchingLogs = DailyLog::withTrashed()->withoutGlobalScopes()
                ->where('employee_id', $employeeId)
                ->where('log_date', $logDate)
                ->get();

            if ($allMatchingLogs->isEmpty()) {
                $allMatchingLogs = DailyLog::withTrashed()->withoutGlobalScopes()
                    ->where('employee_id', $employeeId)
                    ->where('vehicle_id', $vehicleId)
                    ->where('log_date', $logDate)
                    ->get();
            }

            $existing = $allMatchingLogs->first();
            if ($allMatchingLogs->count() > 1) {
                foreach ($allMatchingLogs->slice(1) as $dupLog) {
                    $dupLog->forceDelete();
                }
            }

            if ($existing) {
                if ($existing->trashed()) {
                    $existing->restore();
                }
                $settled = $existing->cash_settled ?? 0;
                $ordersCash = (int) ($logData['orders_cash'] ?? 0);
                $ordersOnline = max(0, $ordersCount - $ordersCash);

                $existing->update([
                    'company_id'            => app()->bound('current_company_id') ? app('current_company_id') : ($request->user()?->company_id ?? 1),
                    'vehicle_id'            => $vehicleId,
                    'contract_id'           => $contractId,
                    'orders_count'          => $ordersCount,
                    'orders_online'         => $ordersOnline,
                    'orders_cash'           => $ordersCash,
                    'rejected_orders_count' => $rejectedOrdersCount,
                    'cash_collected'        => $cashCollected,
                    'cash_pending'          => max(0, $cashCollected - $settled),
                    'online_hours'          => $onlineHours,
                    'zone'                  => $zone,
                    'is_valid'              => $isValid,
                    'shift_valid'           => $isValid,
                    'late_login'            => $lateLogin,
                    'early_logout'          => $earlyLogout,
                    'rate_per_order'        => $rate,
                    'income_amount'         => $income,
                    'driver_status'         => $driverStatus,
                    'notes'                 => $logData['notes'] ?? null,
                ]);
                $savedLogs[] = $existing;
            } else {
                $ordersCash = (int) ($logData['orders_cash'] ?? 0);
                $ordersOnline = max(0, $ordersCount - $ordersCash);

                try {
                    $newLog = DailyLog::create([
                        'company_id'            => app()->bound('current_company_id') ? app('current_company_id') : ($request->user()?->company_id ?? 1),
                        'employee_id'           => $employeeId,
                        'vehicle_id'            => $vehicleId,
                        'contract_id'           => $contractId,
                        'log_date'              => $logDate,
                        'orders_count'          => $ordersCount,
                        'orders_online'         => $ordersOnline,
                        'orders_cash'           => $ordersCash,
                        'rejected_orders_count' => $rejectedOrdersCount,
                        'cash_collected'        => $cashCollected,
                        'cash_settled'          => 0,
                        'cash_pending'          => $cashCollected,
                        'online_hours'          => $onlineHours,
                        'zone'                  => $zone,
                        'is_valid'              => $isValid,
                        'shift_valid'           => $isValid,
                        'late_login'            => $lateLogin,
                        'early_logout'          => $earlyLogout,
                        'created_by'            => $request->user()?->id ?? 1,
                        'rate_per_order'        => $rate,
                        'income_amount'         => $income,
                        'driver_status'         => $driverStatus,
                        'notes'                 => $logData['notes'] ?? null,
                    ]);
                    $savedLogs[] = $newLog;
                } catch (\Illuminate\Database\QueryException $e) {
                    $fallback = DailyLog::withTrashed()->withoutGlobalScopes()
                        ->where('employee_id', $employeeId)
                        ->where('log_date', $logDate)
                        ->first();
                    if (!$fallback) {
                        $fallback = DailyLog::withTrashed()->withoutGlobalScopes()
                            ->where('employee_id', $employeeId)
                            ->where('vehicle_id', $vehicleId)
                            ->where('log_date', $logDate)
                            ->first();
                    }
                    if ($fallback) {
                        if ($fallback->trashed()) {
                            $fallback->restore();
                        }
                        $settled = $fallback->cash_settled ?? 0;
                        $fallback->update([
                            'company_id'            => app()->bound('current_company_id') ? app('current_company_id') : ($request->user()?->company_id ?? 1),
                            'vehicle_id'            => $vehicleId,
                            'contract_id'           => $contractId,
                            'orders_count'          => $ordersCount,
                            'orders_online'         => (int) ($logData['orders_online'] ?? $ordersCount),
                            'orders_cash'           => (int) ($logData['orders_cash'] ?? 0),
                            'rejected_orders_count' => $rejectedOrdersCount,
                            'cash_collected'        => $cashCollected,
                            'cash_pending'          => max(0, $cashCollected - $settled),
                            'online_hours'          => $onlineHours,
                            'zone'                  => $zone,
                            'is_valid'              => $isValid,
                            'shift_valid'           => $isValid,
                            'late_login'            => $lateLogin,
                            'early_logout'          => $earlyLogout,
                            'rate_per_order'        => $rate,
                            'income_amount'         => $income,
                            'driver_status'         => $driverStatus,
                            'notes'                 => $logData['notes'] ?? null,
                        ]);
                        $savedLogs[] = $fallback;
                    } else {
                        throw $e;
                    }
                }
            }
        }

        return response()->json([
            'message' => 'تم حفظ السجلات بنجاح.',
            'count'   => count($savedLogs)
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
     * Update allowed only if log is today or via admin override.
     */
    public function update(Request $request, DailyLog $dailyLog): JsonResponse
    {
        if (!$request->user()->can('daily_logs.edit')) {
            return response()->json(['message' => 'غير مصرح لك بتعديل سجلات التشغيل.'], 403);
        }

        // Auto-adjust orders if needed (e.g. zones contracts where they aren't collected separately)
        $total = $request->has('orders_count') ? (int) $request->input('orders_count') : (int) $dailyLog->orders_count;

        if ($total === 0) {
            $request->merge([
                'orders_online' => 0,
                'orders_cash' => 0
            ]);
        } else {
            $contract = $dailyLog->contract ?? Contract::find($request->input('contract_id'));
            $isZones = false;
            if ($contract) {
                $pricing = $contract->driver_pricing_rules ?? [];
                foreach ($pricing as $rule) {
                    $method = $rule['payment_method'] ?? 'fixed';
                    if ($method === 'zone' || $method === 'zones' || $method === 'zones_tiers') {
                        $isZones = true;
                        break;
                    }
                }
            }

            if ($isZones) {
                $request->merge([
                    'orders_online' => $total,
                    'orders_cash' => 0
                ]);
            } else {
                $hasOnline = $request->has('orders_online');
                $hasCash   = $request->has('orders_cash');
                if (!$hasOnline && !$hasCash && ($dailyLog->orders_online > 0 || $dailyLog->orders_cash > 0)) {
                    // Do not auto-merge when updating orders_count if dailyLog already has orders breakdown
                } else {
                    $online = $request->input('orders_online');
                    $cash   = $request->input('orders_cash');
                    if (($online === null || (int)$online === 0) && ($cash === null || (int)$cash === 0)) {
                        $request->merge([
                            'orders_online' => $total,
                            'orders_cash'   => 0
                        ]);
                    }
                }
            }
        }

        $validator = \Validator::make($request->all(), [
            'orders_count'   => 'sometimes|integer|min:0',
            'orders_online'  => 'sometimes|integer|min:0',
            'orders_cash'    => 'sometimes|integer|min:0',
            'cash_collected' => 'sometimes|numeric|min:0',
            'odometer_start' => 'nullable|integer|min:0',
            'odometer_end'   => 'nullable|integer|min:0',
            'odometer_photo_path' => 'nullable|string',
            'notes'          => 'nullable|string|max:500',
            'online_hours'   => 'nullable|numeric|min:0',
            'ontime_rate'    => 'nullable|numeric|min:0|max:100',
            'avg_delivery_time' => 'nullable|integer|min:0',
            'late_login'     => 'nullable|boolean',
            'early_logout'   => 'nullable|boolean',
            'is_valid'       => 'nullable|boolean',
            'shift_valid'    => 'nullable|boolean',
            'zone'           => 'nullable|string|max:255',
        ]);

        $validator->after(function ($validator) use ($request, $dailyLog) {
            // ── Bug #4: odometer_end >= odometer_start ──
            $start = $request->has('odometer_start') ? $request->input('odometer_start') : $dailyLog->odometer_start;
            $end = $request->has('odometer_end') ? $request->input('odometer_end') : $dailyLog->odometer_end;

            if ($start !== null && $end !== null && $end < $start) {
                $validator->errors()->add('odometer_end', 'قراءة عداد النهاية يجب أن تكون أكبر من أو تساوي قراءة البداية.');
            }

            // ── Bug #5: orders_online + orders_cash == orders_count ──
            $total = $request->has('orders_count') ? (int) $request->input('orders_count') : (int) $dailyLog->orders_count;
            $online = $request->has('orders_online') ? (int) $request->input('orders_online') : (int) $dailyLog->orders_online;
            $cash = $request->has('orders_cash') ? (int) $request->input('orders_cash') : (int) $dailyLog->orders_cash;

            if (($online + $cash) !== $total) {
                $validator->errors()->add('orders_count', 'مجموع طلبات الكاش والأونلاين يجب أن يساوي عدد الطلبات الإجمالي.');
            }

            // Odometer photo validation
            $hasEnd = $request->has('odometer_end') ? $request->filled('odometer_end') : !empty($dailyLog->odometer_end);
            $hasPhoto = $request->has('odometer_photo_path') ? $request->filled('odometer_photo_path') : !empty($dailyLog->odometer_photo_path);

            if ($hasEnd && !$hasPhoto) {
                $validator->errors()->add('odometer_photo_path', 'يجب رفع صورة العداد الحية لتأكيد القراءة.');
            }
        });

        $validated = $validator->validate();

        // Recalculate income if orders changed
        if (isset($validated['orders_count'])) {
            $validated['income_amount'] = $dailyLog->rate_per_order * $validated['orders_count'];
        }

        // Recalculate pending cash if collected changed
        if (isset($validated['cash_collected'])) {
            $validated['cash_pending'] = $validated['cash_collected'] - $dailyLog->cash_settled;
        }

        // Auto-recalculate is_valid
        $contract = $dailyLog->contract;
        if (isset($validated['is_valid'])) {
            // Respect manual override
            $validated['is_valid'] = (bool)$validated['is_valid'];
        } else {
            if ($contract && $contract->is_validity_enabled) {
                $lateLogin = isset($validated['late_login']) ? (bool)$validated['late_login'] : (bool)$dailyLog->late_login;
                $earlyLogout = isset($validated['early_logout']) ? (bool)$validated['early_logout'] : (bool)$dailyLog->early_logout;
                $onlineHours = isset($validated['online_hours']) ? (float)$validated['online_hours'] : (float)$dailyLog->online_hours;
                $ontimeRate = isset($validated['ontime_rate']) ? (float)$validated['ontime_rate'] : (float)$dailyLog->ontime_rate;
                $ordersCount = isset($validated['orders_count']) ? (int)$validated['orders_count'] : (int)$dailyLog->orders_count;

                $validated['is_valid'] = ($onlineHours >= 10.0) && ($ontimeRate >= 90.0) && ($ordersCount >= 2) && !$lateLogin && !$earlyLogout;
            }
        }

        $dailyLog->update($validated);

        return response()->json($dailyLog->fresh());
    }

    /**
     * DELETE /api/daily-logs/{id}
     */
    public function destroy(Request $request, DailyLog $dailyLog): JsonResponse
    {
        if (!$request->user()->can('daily_logs.delete')) {
            return response()->json(['message' => 'غير مصرح لك بحذف سجلات التشغيل.'], 403);
        }

        $dailyLog->delete();
        return response()->json(['message' => 'Log deleted.']);
    }
}
