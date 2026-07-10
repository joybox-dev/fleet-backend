<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayrollPayment;
use App\Models\PayrollSlip;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class PayrollPaymentController extends Controller
{
    public function index($slipId): JsonResponse
    {
        $companyId = app('current_company_id');
        $slip = PayrollSlip::where('company_id', $companyId)->findOrFail($slipId);

        $payments = PayrollPayment::where('payroll_slip_id', $slip->id)->orderByDesc('date')->get();
        return response()->json($payments);
    }

    public function store(Request $request, $slipId): JsonResponse
    {
        $companyId = app('current_company_id');
        $slip = PayrollSlip::where('company_id', $companyId)->findOrFail($slipId);

        $validated = $request->validate([
            'amount' => 'required|numeric',
            'date' => 'required|date',
            'type' => 'required|string|in:disbursement,write_off',
            'payment_method' => 'required_if:type,disbursement|nullable|string|in:bank_transfer,cash',
            'audit_reason' => 'required_if:type,write_off|nullable|string|max:1000',
            'notes' => 'nullable|string|max:255',
        ]);

        $validated['company_id'] = $companyId;
        $validated['payroll_slip_id'] = $slip->id;

        $payment = PayrollPayment::create($validated);

        return response()->json($payment, 201);
    }

    public function destroy($slipId, $id): JsonResponse
    {
        $companyId = app('current_company_id');
        $slip = PayrollSlip::where('company_id', $companyId)->findOrFail($slipId);
        $payment = PayrollPayment::where('payroll_slip_id', $slip->id)->findOrFail($id);

        $payment->delete();
        return response()->json(['message' => 'تم حذف التسوية بنجاح.']);
    }
}
