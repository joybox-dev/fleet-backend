<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CurrencyExchangeRate;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\Rule;

class CurrencyExchangeRateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $rates = CurrencyExchangeRate::when($request->year, fn($q) => $q->where('year', $request->year))
            ->when($request->month, fn($q) => $q->where('month', $request->month))
            ->orderByDesc('year')
            ->orderByDesc('month')
            ->get();

        return response()->json($rates);
    }

    public function store(Request $request): JsonResponse
    {
        $companyId = app('current_company_id');

        $validated = $request->validate([
            'from_currency' => 'required|string|in:SAR,QAR,KWD',
            'to_currency'   => 'required|string|in:KWD',
            'exchange_rate' => 'required|numeric|min:0.000001',
            'year'          => 'required|integer|min:2020|max:2030',
            'month'         => 'required|integer|min:1|max:12',
        ]);

        $rate = CurrencyExchangeRate::updateOrCreate([
            'company_id'    => $companyId,
            'from_currency' => $validated['from_currency'],
            'to_currency'   => $validated['to_currency'],
            'year'          => $validated['year'],
            'month'         => $validated['month'],
        ], [
            'exchange_rate' => $validated['exchange_rate'],
        ]);

        return response()->json($rate, 201);
    }

    public function destroy(CurrencyExchangeRate $currencyExchangeRate): JsonResponse
    {
        $currencyExchangeRate->delete();
        return response()->json(['message' => 'تم حذف سعر الصرف بنجاح.']);
    }
}
