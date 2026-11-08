<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\TalabatStatement;
use App\Services\TalabatImportService;
use App\Services\TalabatRevenueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Talabat's monthly payment statement on a contract: the month as Talabat settles it, and the import
 * that feeds it.
 */
class TalabatSettlementController extends Controller
{
    /**
     * GET /api/contracts/{contract}/talabat?year=&month=
     */
    public function show(Request $request, Contract $contract): JsonResponse
    {
        $year = (int) $request->get('year', now()->year);
        $month = (int) $request->get('month', now()->month);
        $applies = TalabatRevenueService::appliesTo($contract, $year, $month);

        return response()->json([
            'contract' => ['id' => $contract->id, 'name' => $contract->name],
            'year' => $year,
            'month' => $month,
            'settlement_from' => $contract->talabat_settlement_from ? Carbon::parse($contract->talabat_settlement_from)->format('Y-m') : null,
            'rates' => TalabatRevenueService::rates($contract),
            'applies' => $applies,
            'result' => $applies ? TalabatRevenueService::forMonth($contract, $year, $month) : null,
            'statements' => TalabatStatement::where('contract_id', $contract->id)
                ->orderByDesc('year')->orderByDesc('month')
                ->get()
                ->map(fn (TalabatStatement $s) => [
                    'id' => $s->id,
                    'year' => $s->year,
                    'month' => $s->month,
                    'riders_count' => $s->riders_count,
                    'deliveries' => $s->deliveries,
                    'riders_total' => round((float) $s->riders_total, 3),
                    'company_total' => round((float) $s->company_total, 3),
                    'net_payment' => round((float) $s->net_payment, 3),
                    'original_filename' => $s->original_filename,
                    'created_at' => $s->created_at?->toDateTimeString(),
                ])
                ->values(),
        ]);
    }

    /**
     * POST /api/contracts/{contract}/talabat/statement/preview
     */
    public function preview(Request $request, Contract $contract): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240'], [
            'file.required' => 'اختر ملف كشف طلبات.',
            'file.mimes' => 'ملف كشف طلبات يجب أن يكون CSV أو Excel.',
        ]);

        return response()->json(TalabatImportService::preview($contract, $request->file('file')));
    }

    /**
     * POST /api/contracts/{contract}/talabat/statement
     */
    public function store(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate([
            'token' => 'required|string',
            'links' => 'nullable|array',
            'links.*' => 'nullable|integer',
        ]);
        $saved = TalabatImportService::confirm($contract, $data['token'], $data['links'] ?? [], $request->user());
        $s = $saved['statement'];

        return response()->json([
            'message' => sprintf('حُفظ كشف طلبات لشهر %02d/%d: إيراد %s د.ك، والتحويل %s د.ك.', $s->month, $s->year, number_format((float) $s->riders_total, 3), number_format((float) $s->net_payment, 3)),
            'statement_id' => $s->id,
            'year' => $s->year,
            'month' => $s->month,
            'linked' => $saved['linked'],
            'settlement_from' => $saved['settlement_from'],
        ], 201);
    }

    /**
     * DELETE /api/contracts/{contract}/talabat/statement/{statement}
     */
    public function destroy(Contract $contract, TalabatStatement $statement): JsonResponse
    {
        abort_unless((int) $statement->contract_id === (int) $contract->id, 404);
        $statement->riders()->delete();
        $statement->delete();
        TalabatRevenueService::forget();

        return response()->json(['message' => sprintf('حُذف كشف طلبات لشهر %02d/%d، وعاد الشهر تقديراً.', $statement->month, $statement->year)]);
    }
}
