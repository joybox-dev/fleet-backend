<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CashSettlement;
use App\Models\DailyLog;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashSettlementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $settlements = CashSettlement::with(['employee:id,name', 'receivedBy:id,name'])
            ->when($request->employee_id, fn ($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->date_from, fn ($q) => $q->whereDate('settlement_date', '>=', $request->date_from))
            ->when($request->date_to, fn ($q) => $q->whereDate('settlement_date', '<=', $request->date_to))
            ->orderByDesc('settlement_date')
            ->paginate(50);

        return response()->json($settlements);
    }

    /**
     * GET /api/cash-settlements/pending
     * All drivers with outstanding cash — supports real-time database-level search.
     */
    public function pending(Request $request): JsonResponse
    {
        $search = $request->query('search');

        // The cash is owed by the driver, not by the vehicle. Grouping by both used to split one
        // driver into a card per plate, and settling from one card ate the other card's oldest days.
        $pendingQuery = DailyLog::where('cash_pending', '>', 0)
            ->with(['employee:id,name,phone'])
            ->selectRaw('employee_id, SUM(cash_pending) as total_pending, COUNT(*) as days_outstanding, MIN(log_date) as oldest_date')
            ->groupBy('employee_id')
            ->orderByDesc('total_pending');

        if ($search) {
            $pendingQuery->where(function ($q) use ($search) {
                $q->whereHas('employee', function ($eq) use ($search) {
                    $eq->where('name', 'like', '%'.$search.'%')
                        ->orWhere('phone', 'like', '%'.$search.'%');
                })->orWhereHas('vehicle', function ($vq) use ($search) {
                    $vq->where('plate_number', 'like', '%'.$search.'%');
                });
            });
        }

        $rows = $pendingQuery->get();

        // The plates behind each driver's balance, for the card subtitle.
        $platesByEmployee = DailyLog::where('cash_pending', '>', 0)
            ->whereIn('employee_id', $rows->pluck('employee_id'))
            ->with('vehicle:id,plate_number')
            ->get(['employee_id', 'vehicle_id'])
            ->groupBy('employee_id')
            ->map(fn ($logs) => $logs->pluck('vehicle.plate_number')->filter()->unique()->values()->all());

        $pending = $rows->map(function ($row) use ($platesByEmployee) {
            $plates = $platesByEmployee[$row->employee_id] ?? [];

            return [
                'employee_id' => $row->employee_id,
                'employee_name' => $row->employee?->name,
                'employee_phone' => $row->employee?->phone,
                'vehicle_plate' => $plates[0] ?? null,
                'vehicle_plates' => $plates,
                'total_pending' => (float) $row->total_pending,
                'days_outstanding' => (int) $row->days_outstanding,
                'oldest_date' => $row->oldest_date,
                // Days since the oldest unsettled day — the card used to call the *number of
                // pending records* "days late", which is a different number entirely.
                'days_late' => $row->oldest_date
                    ? (int) Carbon::parse($row->oldest_date)->diffInDays(now()->startOfDay())
                    : 0,
            ];
        });

        $totalCompanyPending = DailyLog::where('cash_pending', '>', 0)->sum('cash_pending');

        return response()->json([
            'total_pending_kwd' => (float) $totalCompanyPending,
            'drivers' => $pending,
        ]);
    }

    /**
     * POST /api/cash-settlements
     * Record cash handover — reduces pending cash on daily logs.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|exists:employees,id',
            'daily_log_id' => 'nullable|exists:daily_logs,id',
            'settlement_date' => 'required|date',
            'amount' => 'required|numeric|min:0.001',
            'receipt_photo_path' => 'nullable|string',
            'notes' => 'nullable|string',
        ]);

        return DB::transaction(function () use ($request, $validated) {
            if ($validated['daily_log_id'] ?? null) {
                $log = DailyLog::find($validated['daily_log_id']);
                if ($validated['amount'] > $log->cash_pending) {
                    return response()->json([
                        'message' => 'المبلغ المدخل للتسوية أكبر من الكاش المعلق لهذا السجل اليومي.',
                        'errors' => [
                            'amount' => ['المبلغ المدخل للتسوية أكبر من الكاش المعلق لهذا السجل اليومي.'],
                        ],
                    ], 422);
                }
            } else {
                $pendingCash = DailyLog::where('employee_id', $validated['employee_id'])->sum('cash_pending');
                if ($validated['amount'] > $pendingCash) {
                    return response()->json([
                        'message' => 'المبلغ المدخل للتسوية أكبر من الكاش المعلق الحالي للسائق.',
                        'errors' => [
                            'amount' => ['المبلغ المدخل للتسوية أكبر من الكاش المعلق الحالي للسائق.'],
                        ],
                    ], 422);
                }
            }

            $validated['received_by'] = $request->user()->id;
            $validated['company_id'] = app('current_company_id');

            $settlement = CashSettlement::create($validated);

            $remaining = (float) $validated['amount'];

            if ($validated['daily_log_id'] ?? null) {
                $log = DailyLog::find($validated['daily_log_id']);
                $reduce = min($remaining, (float) $log->cash_pending);
                DB::table('daily_logs')
                    ->where('id', $log->id)
                    ->update([
                        'cash_settled' => round((float) $log->cash_settled + $reduce, 3),
                        'cash_pending' => max(0, round((float) $log->cash_pending - $reduce, 3)),
                        'updated_at' => now(),
                    ]);
            } else {
                $logs = DailyLog::where('employee_id', $validated['employee_id'])
                    ->where('cash_pending', '>', 0)
                    ->orderBy('log_date')
                    ->get();

                foreach ($logs as $log) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $reduce = min($remaining, (float) $log->cash_pending);
                    DB::table('daily_logs')
                        ->where('id', $log->id)
                        ->update([
                            'cash_settled' => round((float) $log->cash_settled + $reduce, 3),
                            'cash_pending' => max(0, round((float) $log->cash_pending - $reduce, 3)),
                            'updated_at' => now(),
                        ]);
                    $remaining -= $reduce;
                }
            }

            return response()->json($settlement->load(['employee:id,name', 'receivedBy:id,name']), 201);
        }, 3);
    }
}
