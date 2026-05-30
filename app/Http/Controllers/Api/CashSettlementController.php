<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\CashSettlement;
use App\Models\DailyLog;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class CashSettlementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $settlements = CashSettlement::with(['employee:id,name', 'receivedBy:id,name'])
            ->when($request->employee_id, fn($q) => $q->where('employee_id', $request->employee_id))
            ->when($request->date_from, fn($q) => $q->whereDate('settlement_date', '>=', $request->date_from))
            ->when($request->date_to, fn($q) => $q->whereDate('settlement_date', '<=', $request->date_to))
            ->orderByDesc('settlement_date')
            ->paginate(50);

        return response()->json($settlements);
    }

    /**
     * GET /api/cash-settlements/pending
     * All drivers with outstanding cash — main dashboard concern from meeting.
     */
    public function pending(): JsonResponse
    {
        $pending = DailyLog::where('cash_pending', '>', 0)
            ->with('employee:id,name,phone')
            ->with('vehicle:id,plate_number')
            ->selectRaw('employee_id, vehicle_id, SUM(cash_pending) as total_pending, COUNT(*) as days_outstanding')
            ->groupBy('employee_id', 'vehicle_id')
            ->orderByDesc('total_pending')
            ->get()
            ->map(fn($row) => [
                'employee_id'      => $row->employee_id,
                'employee_name'    => $row->employee?->name,
                'employee_phone'   => $row->employee?->phone,
                'vehicle_plate'    => $row->vehicle?->plate_number,
                'total_pending'    => (float) $row->total_pending,
                'days_outstanding' => (int) $row->days_outstanding,
            ]);

        return response()->json([
            'total_pending_kwd' => $pending->sum('total_pending'),
            'drivers'           => $pending,
        ]);
    }

    /**
     * POST /api/cash-settlements
     * Record cash handover — reduces pending cash on daily logs.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'employee_id'       => 'required|exists:employees,id',
            'daily_log_id'      => 'nullable|exists:daily_logs,id',
            'settlement_date'   => 'required|date',
            'amount'            => 'required|numeric|min:0.001',
            'receipt_photo_path'=> 'nullable|string',
            'notes'             => 'nullable|string',
        ]);

        if ($validated['daily_log_id'] ?? null) {
            $log = DailyLog::find($validated['daily_log_id']);
            if ($validated['amount'] > $log->cash_pending) {
                return response()->json([
                    'message' => 'المبلغ المدخل للتسوية أكبر من الكاش المعلق لهذا السجل اليومي.',
                    'errors' => [
                        'amount' => ['المبلغ المدخل للتسوية أكبر من الكاش المعلق لهذا السجل اليومي.']
                    ]
                ], 422);
            }
        } else {
            $pendingCash = DailyLog::where('employee_id', $validated['employee_id'])->sum('cash_pending');
            if ($validated['amount'] > $pendingCash) {
                return response()->json([
                    'message' => 'المبلغ المدخل للتسوية أكبر من الكاش المعلق الحالي للسائق.',
                    'errors' => [
                        'amount' => ['المبلغ المدخل للتسوية أكبر من الكاش المعلق الحالي للسائق.']
                    ]
                ], 422);
            }
        }

        $validated['received_by'] = $request->user()->id;

        $settlement = CashSettlement::create($validated);

        // Reduce cash_pending on linked logs or spread across oldest unpaid
        $remaining = $validated['amount'];

        if ($validated['daily_log_id'] ?? null) {
            // Settle specific log
            $log = DailyLog::find($validated['daily_log_id']);
            $reduce = min($remaining, $log->cash_pending);
            $log->update([
                'cash_settled' => $log->cash_settled + $reduce,
                'cash_pending' => max(0, $log->cash_pending - $reduce),
            ]);
        } else {
            // Spread across oldest logs for this employee (FIFO)
            DailyLog::where('employee_id', $validated['employee_id'])
                ->where('cash_pending', '>', 0)
                ->orderBy('log_date')
                ->each(function ($log) use (&$remaining) {
                    if ($remaining <= 0) return false;
                    $reduce = min($remaining, $log->cash_pending);
                    $log->update([
                        'cash_settled' => $log->cash_settled + $reduce,
                        'cash_pending' => max(0, $log->cash_pending - $reduce),
                    ]);
                    $remaining -= $reduce;
                });
        }



        return response()->json($settlement->load(['employee:id,name', 'receivedBy:id,name']), 201);
    }
}
