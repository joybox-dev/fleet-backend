<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contract;
use App\Models\KeetaInvoice;
use App\Models\KeetaLevelSnapshot;
use App\Services\KeetaImportService;
use App\Services\KeetaRevenueService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Keeta's statement and «expected level» export on a contract: the month as Keeta settles it, and
 * the two imports that feed it.
 */
class KeetaSettlementController extends Controller
{
    /**
     * GET /api/contracts/{contract}/keeta?year=&month=
     */
    public function show(Request $request, Contract $contract): JsonResponse
    {
        $year = (int) $request->get('year', now()->year);
        $month = (int) $request->get('month', now()->month);
        $applies = KeetaRevenueService::appliesTo($contract, $year, $month);

        return response()->json([
            'contract' => ['id' => $contract->id, 'name' => $contract->name],
            'year' => $year,
            'month' => $month,
            'settlement_from' => $contract->keeta_settlement_from ? Carbon::parse($contract->keeta_settlement_from)->format('Y-m') : null,
            'applies' => $applies,
            'result' => $applies ? KeetaRevenueService::forMonth($contract, $year, $month) : null,
            // Each statement as its invoice adds up, so the screens can write the sum out.
            'statements' => KeetaInvoice::where('contract_id', $contract->id)
                ->orderByDesc('year')->orderByDesc('month')
                ->get()
                ->map(fn (KeetaInvoice $invoice) => [
                    'id' => $invoice->id,
                    'year' => $invoice->year,
                    'month' => $invoice->month,
                    'billing_cycle' => $invoice->billing_cycle,
                    'riders_count' => (int) $invoice->riders_count,
                    'original_filename' => $invoice->original_filename,
                    'created_at' => $invoice->created_at?->toDateTimeString(),
                ] + $invoice->breakdown())
                ->values(),
            'level_snapshots' => KeetaLevelSnapshot::where('contract_id', $contract->id)
                ->where('year', $year)->where('month', $month)
                ->withCount('rows')
                ->orderByDesc('taken_on')->orderByDesc('id')
                ->get(['id', 'year', 'month', 'taken_on', 'original_filename', 'created_at']),
        ]);
    }

    /**
     * POST /api/contracts/{contract}/keeta/statement/preview
     */
    public function previewStatement(Request $request, Contract $contract): JsonResponse
    {
        $request->validate(['file' => 'required|file|mimes:xlsx,xls|max:20480'], [
            'file.required' => 'اختر ملف كشف كيتا.',
            'file.mimes' => 'ملف كشف كيتا يجب أن يكون Excel.',
        ]);

        return response()->json(KeetaImportService::previewStatement($contract, $request->file('file')));
    }

    /**
     * POST /api/contracts/{contract}/keeta/statement
     */
    public function storeStatement(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string']);
        $saved = KeetaImportService::confirmStatement($contract, $data['token'], $request->user());
        $invoice = $saved['invoice'];

        return response()->json([
            'message' => sprintf('حُفظ كشف كيتا لشهر %02d/%d: %s د.ك.', $invoice->month, $invoice->year, number_format((float) $invoice->invoice_amount, 3)),
            'invoice_id' => $invoice->id,
            'year' => $invoice->year,
            'month' => $invoice->month,
            'settlement_from' => $saved['settlement_from'],
        ], 201);
    }

    /**
     * DELETE /api/contracts/{contract}/keeta/statement/{invoice}
     */
    public function destroyStatement(Contract $contract, KeetaInvoice $invoice): JsonResponse
    {
        abort_unless((int) $invoice->contract_id === (int) $contract->id, 404);
        $invoice->riders()->delete();
        $invoice->lines()->delete();
        $invoice->delete();
        KeetaRevenueService::forget();

        return response()->json(['message' => sprintf('حُذف كشف كيتا لشهر %02d/%d، وعاد الشهر تقديراً.', $invoice->month, $invoice->year)]);
    }

    /**
     * POST /api/contracts/{contract}/keeta/levels/preview
     */
    public function previewLevels(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate([
            'file' => 'required|file|mimes:xlsx,xls|max:5120',
            'year' => 'required|integer|min:2024|max:2100',
            'month' => 'required|integer|min:1|max:12',
            'taken_on' => 'nullable|date',
        ], [
            'file.required' => 'اختر ملف المستوى المتوقع.',
            'file.mimes' => 'ملف المستوى المتوقع يجب أن يكون Excel.',
        ]);
        $takenOn = isset($data['taken_on']) ? Carbon::parse($data['taken_on'])->toDateString() : now()->toDateString();

        return response()->json(KeetaImportService::previewLevels($contract, $request->file('file'), (int) $data['year'], (int) $data['month'], $takenOn));
    }

    /**
     * POST /api/contracts/{contract}/keeta/levels
     */
    public function storeLevels(Request $request, Contract $contract): JsonResponse
    {
        $data = $request->validate(['token' => 'required|string']);
        $snapshot = KeetaImportService::confirmLevels($contract, $data['token'], $request->user());

        return response()->json([
            'message' => sprintf('حُفظ ملف المستوى المتوقع لشهر %02d/%d بتاريخ %s.', $snapshot->month, $snapshot->year, $snapshot->taken_on->toDateString()),
            'snapshot_id' => $snapshot->id,
        ], 201);
    }

    /**
     * DELETE /api/contracts/{contract}/keeta/levels/{snapshot}
     */
    public function destroyLevels(Contract $contract, KeetaLevelSnapshot $snapshot): JsonResponse
    {
        abort_unless((int) $snapshot->contract_id === (int) $contract->id, 404);
        $snapshot->rows()->delete();
        $snapshot->delete();
        KeetaRevenueService::forget();

        return response()->json(['message' => 'حُذف ملف المستوى المتوقع.']);
    }
}
