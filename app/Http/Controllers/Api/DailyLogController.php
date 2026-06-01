<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\DailyLog;
use App\Models\Contract;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class DailyLogController extends Controller
{
    /**
     * GET /api/daily-logs
     * List logs with filters: date range, employee, vehicle, contract.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max($request->integer('per_page', 50), 5), 100);
        $logs = DailyLog::with(['employee:id,name', 'vehicle:id,plate_number', 'contract:id,name,payment_type'])
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
        $validator = \Validator::make($request->all(), [
            'employee_id'    => 'required|exists:employees,id',
            'vehicle_id'     => 'required|exists:vehicles,id',
            'contract_id'    => 'required|exists:contracts,id',
            'log_date'       => 'required|date',
            'orders_count'   => 'required|integer|min:0',
            'orders_online'  => 'nullable|integer|min:0',
            'orders_cash'    => 'nullable|integer|min:0',
            'cash_collected' => 'nullable|numeric|min:0',
            'odometer_start' => 'nullable|integer|min:0',
            'odometer_end'   => 'nullable|integer|min:0|gte:odometer_start',
            'notes'          => 'nullable|string|max:500',
        ]);

        $validator->after(function ($validator) use ($request) {
            $total = (int) $request->input('orders_count', 0);
            $online = (int) $request->input('orders_online', 0);
            $cash = (int) $request->input('orders_cash', 0);

            if (($online + $cash) !== $total) {
                $validator->errors()->add('orders_count', 'مجموع طلبات الكاش والأونلاين يجب أن يساوي عدد الطلبات الإجمالي.');
            }
        });

        $validated = $validator->validate();

        // Prevent duplicate log for same employee on same date
        if (DailyLog::where('employee_id', $validated['employee_id'])->where('log_date', $validated['log_date'])->exists()) {
            return response()->json(['message' => 'A daily log already exists for this employee on this date.'], 422);
        }

        // Fetch contract to snapshot rate and auto-calculate income
        $contract = Contract::findOrFail($validated['contract_id']);
        $rate     = $contract->rate_per_order;
        $income   = $rate * $validated['orders_count'];

        // Cash pending = collected - settled (no settlement yet on creation)
        $cashCollected = $validated['cash_collected'] ?? 0;

        $log = DailyLog::create(array_merge($validated, [
            'created_by'      => $request->user()->id,
            'rate_per_order'  => $rate,
            'income_amount'   => $income,
            'orders_online'   => $validated['orders_online'] ?? 0,
            'orders_cash'     => $validated['orders_cash'] ?? 0,
            'cash_collected'  => $cashCollected,
            'cash_settled'    => 0,
            'cash_pending'    => $cashCollected,
        ]));

        return response()->json($log->load(['employee:id,name', 'vehicle:id,plate_number']), 201);
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
        $validator = \Validator::make($request->all(), [
            'orders_count'   => 'sometimes|integer|min:0',
            'orders_online'  => 'sometimes|integer|min:0',
            'orders_cash'    => 'sometimes|integer|min:0',
            'cash_collected' => 'sometimes|numeric|min:0',
            'odometer_start' => 'nullable|integer|min:0',
            'odometer_end'   => 'nullable|integer|min:0',
            'notes'          => 'nullable|string|max:500',
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

        $dailyLog->update($validated);

        return response()->json($dailyLog->fresh());
    }

    /**
     * DELETE /api/daily-logs/{id}
     */
    public function destroy(DailyLog $dailyLog): JsonResponse
    {
        $dailyLog->delete();
        return response()->json(['message' => 'Log deleted.']);
    }
}
