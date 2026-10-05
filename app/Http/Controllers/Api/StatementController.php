<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Company;
use App\Models\Employee;
use App\Models\OperationalAdvance;
use App\Models\OperationalAdvanceFund;
use App\Services\OperationalFundService;
use App\Services\StatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Statements of account («كشف حساب»): a driver's pay account, an employee's custody account and a
 * client's receivable, each over any period. Each account opens with the permission that already
 * guards its money; custody stays as private as the custody screen — one's own, unless the
 * «إعطاء رصيد» permission makes him the custodian of all of them.
 */
class StatementController extends Controller
{
    /** Who the reader may pick, by account, so the picker never depends on another screen's gate. */
    public function parties(Request $request): JsonResponse
    {
        $user = $request->user();
        $companyId = $this->currentCompanyId();
        $employees = fn () => Employee::query()->orderBy('name')->get(['id', 'name', 'employee_number', 'role_category', 'status']);

        $result = ['pay' => [], 'custody' => [], 'client' => []];

        if ($user->can('payroll.view') || $user->can('contract_payroll.view')) {
            $result['pay'] = $employees()->map(fn ($e) => self::party($e))->values();
        }

        if ($user->can('op_advances.view')) {
            if (OperationalFundService::seesAll($user)) {
                $holders = OperationalAdvance::withoutGlobalScopes()->where('company_id', $companyId)->pluck('employee_id')
                    ->merge(OperationalAdvance::withoutGlobalScopes()->where('company_id', $companyId)->whereNotNull('funded_by_employee_id')->pluck('funded_by_employee_id'))
                    ->merge(OperationalAdvanceFund::withoutGlobalScopes()->where('company_id', $companyId)->pluck('employee_id'))
                    ->unique()->all();
                $result['custody'] = Employee::query()->whereIn('id', $holders)->orderBy('name')->get(['id', 'name', 'employee_number', 'role_category', 'status'])
                    ->map(fn ($e) => self::party($e))->values();
            } elseif ($own = OperationalFundService::employeeFor($user, $companyId)) {
                $result['custody'] = [self::party($own)];
            }
        }

        if ($user->can('reports.view')) {
            $result['client'] = Client::query()->orderBy('name')->get(['id', 'name', 'name_ar'])
                ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name_ar ?: $c->name, 'number' => null])->values();
        }

        return response()->json($result);
    }

    public function pay(Request $request, Employee $employee): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return $this->respond(StatementService::forEmployeePay($employee, $from, $to));
    }

    public function custody(Request $request, Employee $employee): JsonResponse
    {
        $user = $request->user();
        if (! OperationalFundService::seesAll($user) && OperationalFundService::employeeFor($user, $this->currentCompanyId())?->id !== $employee->id) {
            return response()->json(['message' => 'غير مصرح لك — كشف عهدة موظف آخر يحتاج صلاحية «إعطاء رصيد».'], 403);
        }
        [$from, $to] = $this->period($request);

        return $this->respond(StatementService::forEmployeeCustody($employee, $from, $to));
    }

    public function client(Request $request, Client $client): JsonResponse
    {
        [$from, $to] = $this->period($request);

        return $this->respond(StatementService::forClient($client, $from, $to));
    }

    /** @param  array<string, mixed>  $statement */
    private function respond(array $statement): JsonResponse
    {
        $company = Company::find($this->currentCompanyId());

        return response()->json($statement + ['company_name' => $company?->name_ar ?: $company?->name]);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function period(Request $request): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        return [$validated['from'] ?? null, $validated['to'] ?? null];
    }

    /** @return array{id: int, name: string, number: ?string, role_category: ?string, status: ?string} */
    private static function party(Employee $employee): array
    {
        return [
            'id' => $employee->id,
            'name' => $employee->name,
            'number' => $employee->employee_number,
            'role_category' => $employee->role_category,
            'status' => $employee->status,
        ];
    }
}
