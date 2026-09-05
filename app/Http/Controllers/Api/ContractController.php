<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Contract;
use App\Models\ContractMonthlyParameter;
use App\Models\DailyLog;
use App\Services\ContractScopeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContractController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $allowedIds = ContractScopeService::getAllocatedContractIds();
        $startOfMonth = now()->startOfMonth()->toDateString();
        $endOfMonth = now()->endOfMonth()->toDateString();

        $query = Contract::with(['client:id,name', 'vehicleType:id,name'])
            ->when($allowedIds !== null, fn ($q) => $q->whereIn('id', $allowedIds))
            ->when($request->client_id, fn ($q) => $q->where('client_id', $request->client_id))
            ->when($request->status, fn ($q) => $q->where('status', $request->status))
            ->when($request->boolean('active_only'), fn ($q) => $q->where('status', 'active'))
            ->when($request->vehicle_type_id, fn ($q) => $q->where(function ($query) use ($request) {
                $query->where('vehicle_type_id', $request->vehicle_type_id)
                    ->orWhereNull('vehicle_type_id');
            }))
            ->withCount([
                'assignments as active_drivers_count' => fn ($q) => $q->where('status', 'active'),
            ])
            ->withSum([
                'dailyLogs as current_month_orders' => fn ($q) => $q->whereBetween('log_date', [$startOfMonth, $endOfMonth]),
            ], 'orders_count')
            ->withSum([
                'dailyLogs as current_month_cash' => fn ($q) => $q->whereBetween('log_date', [$startOfMonth, $endOfMonth]),
            ], 'cash_collected')
            ->orderByRaw("FIELD(status, 'active', 'suspended', 'ended') ASC")
            ->orderBy('name');

        $contracts = $query->paginate(100);

        return response()->json($contracts);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $request->user()->can('contracts.create')) {
            return response()->json(['message' => 'غير مصرح لك بإضافة عقد جديد.'], 403);
        }

        $companyId = app('current_company_id');

        $validated = $request->validate([
            'client_id' => 'required|exists:clients,id',
            'contract_number' => [
                'required',
                'string',
                Rule::unique('contracts', 'contract_number')->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'name' => 'required|string|max:255',
            'client_name' => 'nullable|string|max:255',
            'status' => 'nullable|in:active,suspended,ended',
            'currency' => 'nullable|in:KWD,SAR,QAR',
            'payment_type' => 'required|in:per_order,hourly,fixed,hybrid',

            // Backwards compatibility
            'rate_per_order' => 'nullable|numeric|min:0',
            'fixed_monthly' => 'nullable|numeric|min:0',

            // Defaults and customizations
            'default_order_commission' => 'nullable|numeric|min:0',
            'default_absence_divisor' => 'nullable|integer|min:1',
            'default_monthly_target' => 'nullable|integer|min:0',
            'default_daily_target' => 'nullable|integer|min:0',
            'required_vehicles_count' => 'nullable|integer|min:0',

            'expected_monthly_revenue' => 'nullable|numeric|min:0',
            'expected_monthly_expenses' => 'nullable|numeric|min:0',
            'target_profit_margin' => 'nullable|numeric|min:0|max:100',
            'expected_total_profit' => 'nullable|numeric|min:0',

            'default_required_valid_days' => 'nullable|integer|min:0',
            // Payroll prices every month per day against this. It was optional and blank on
            // 15 of 18 contracts, and a hardcoded 28 quietly stood in for it.
            'default_required_work_days' => 'required|integer|min:1|max:31',

            'start_date' => 'required|date',
            'end_date' => 'nullable|date|after:start_date',
            'is_validity_enabled' => 'nullable|boolean',
            'notes' => 'nullable|string|max:1000',

            // New Pricing Rules & Vehicle Types
            'vehicle_type_id' => 'nullable|exists:vehicle_types,id',
            // How the contract bills and how it pays are the two numbers everything else is built
            // from, so neither may be left for the engines to guess at.
            'client_payment_method' => 'required|string|in:fixed,zones,tiers,hybrid',
            'client_pricing_rules' => [
                'required', 'array',
                function ($attribute, $value, $fail) use ($request) {
                    foreach ($this->pricingRuleProblems($value, $request->input('client_payment_method'), 'client') as $problem) {
                        $fail($problem);
                    }
                },
            ],
            'driver_payment_method' => [
                'required',
                'string',
                'in:fixed,zones,tiers,hybrid,zones_tiers',
                function ($attribute, $value, $fail) use ($request) {
                    $clientMethod = $request->input('client_payment_method');
                    if (in_array($value, ['zones', 'zones_tiers']) && $clientMethod !== 'zones') {
                        $fail('لا يمكن تعيين طريقة دفع السائق بناءً على الفئات (Zones) إذا لم تكن طريقة دفع العميل هي الفئات.');
                    }
                },
            ],
            'driver_pricing_rules' => [
                'required', 'array',
                function ($attribute, $value, $fail) use ($request) {
                    foreach ($this->pricingRuleProblems($value, $request->input('driver_payment_method'), 'driver') as $problem) {
                        $fail($problem);
                    }
                },
            ],
            'capacity_target' => 'nullable|integer|min:0',
            'capacity_pricing_rules' => 'nullable|array',
        ]);

        if (empty($validated['client_name'])) {
            $client = Client::find($validated['client_id']);
            $validated['client_name'] = $client ? $client->name : 'Default Client';
        }
        if (empty($validated['status'])) {
            $validated['status'] = 'active';
        }
        if (empty($validated['currency'])) {
            $validated['currency'] = 'KWD';
        }

        if (isset($validated['rate_per_order']) && ! isset($validated['default_order_commission'])) {
            $validated['default_order_commission'] = $validated['rate_per_order'];
        }

        $contract = Contract::create($validated);

        return response()->json($contract->load('client:id,name'), 201);
    }

    public function show(Contract $contract): JsonResponse
    {
        $allowedIds = ContractScopeService::getAllocatedContractIds();
        if ($allowedIds !== null && ! in_array($contract->id, $allowedIds)) {
            return response()->json(['message' => 'عذراً، ليس لديك صلاحية للوصول لهذا العقد.'], 403);
        }

        return response()->json($contract->load(['client', 'assignments.employee', 'monthlyParameters', 'bonuses']));
    }

    public function update(Request $request, Contract $contract): JsonResponse
    {
        if (! $request->user()->can('contracts.edit')) {
            return response()->json(['message' => 'غير مصرح لك بتعديل العقود.'], 403);
        }

        $companyId = app('current_company_id');

        $validated = $request->validate([
            'client_id' => 'sometimes|exists:clients,id',
            'contract_number' => [
                'sometimes',
                'string',
                Rule::unique('contracts', 'contract_number')->ignore($contract->id)->where('company_id', $companyId)->whereNull('deleted_at'),
            ],
            'name' => 'sometimes|string|max:255',
            'client_name' => 'sometimes|string|max:255',
            'status' => 'sometimes|in:active,suspended,ended',
            'currency' => 'sometimes|in:KWD,SAR,QAR',
            'payment_type' => 'sometimes|in:per_order,hourly,fixed,hybrid',

            // Backwards compatibility
            'rate_per_order' => 'nullable|numeric|min:0',
            'fixed_monthly' => 'nullable|numeric|min:0',

            'default_order_commission' => 'nullable|numeric|min:0',
            'default_absence_divisor' => 'nullable|integer|min:1',
            'default_monthly_target' => 'nullable|integer|min:0',
            'default_daily_target' => 'nullable|integer|min:0',
            'required_vehicles_count' => 'nullable|integer|min:0',

            'expected_monthly_revenue' => 'nullable|numeric|min:0',
            'expected_monthly_expenses' => 'nullable|numeric|min:0',
            'target_profit_margin' => 'nullable|numeric|min:0|max:100',
            'expected_total_profit' => 'nullable|numeric|min:0',

            'default_required_valid_days' => 'nullable|integer|min:0',
            // Payroll prices every month per day against this. It was optional and blank on
            // 15 of 18 contracts, and a hardcoded 28 quietly stood in for it.
            // On update it is only demanded from a contract that never had one, so editing an
            // unrelated field on a contract that already states its days still works.
            'default_required_work_days' => [
                Rule::requiredIf(fn () => (int) $contract->default_required_work_days <= 0),
                'integer', 'min:1', 'max:31',
            ],

            'start_date' => 'sometimes|date',
            'end_date' => 'nullable|date',
            'is_active' => 'sometimes|boolean',
            'is_validity_enabled' => 'sometimes|boolean',
            'notes' => 'nullable|string|max:1000',

            // New Pricing Rules & Vehicle Types
            'vehicle_type_id' => 'nullable|exists:vehicle_types,id',
            // Demanded only from a contract that does not already state it, so editing an unrelated
            // field on a contract set up long ago still works — but a contract missing its billing
            // method has to gain one the first time anybody touches it.
            'client_payment_method' => [
                Rule::requiredIf(fn () => blank($contract->client_payment_method)),
                'string', 'in:fixed,zones,tiers,hybrid',
            ],
            'client_pricing_rules' => [
                Rule::requiredIf(fn () => ! is_array($contract->client_pricing_rules) || $contract->client_pricing_rules === []),
                'array',
                function ($attribute, $value, $fail) use ($request, $contract) {
                    $method = $request->input('client_payment_method') ?? $contract->client_payment_method;
                    foreach ($this->pricingRuleProblems($value, $method, 'client') as $problem) {
                        $fail($problem);
                    }
                },
            ],
            'driver_payment_method' => [
                Rule::requiredIf(fn () => blank($contract->driver_payment_method)),
                'string',
                'in:fixed,zones,tiers,hybrid,zones_tiers',
                function ($attribute, $value, $fail) use ($request, $contract) {
                    $clientMethod = $request->input('client_payment_method') ?? $contract->client_payment_method;
                    if (in_array($value, ['zones', 'zones_tiers']) && $clientMethod !== 'zones') {
                        $fail('لا يمكن تعيين طريقة دفع السائق بناءً على الفئات (Zones) إذا لم تكن طريقة دفع العميل هي الفئات.');
                    }
                },
            ],
            'driver_pricing_rules' => [
                Rule::requiredIf(fn () => ! is_array($contract->driver_pricing_rules) || $contract->driver_pricing_rules === []),
                'array',
                function ($attribute, $value, $fail) use ($request, $contract) {
                    $method = $request->input('driver_payment_method') ?? $contract->driver_payment_method;
                    foreach ($this->pricingRuleProblems($value, $method, 'driver') as $problem) {
                        $fail($problem);
                    }
                },
            ],
            'capacity_target' => 'nullable|integer|min:0',
            'capacity_pricing_rules' => 'nullable|array',
        ]);

        if (isset($validated['rate_per_order']) && ! isset($validated['default_order_commission'])) {
            $validated['default_order_commission'] = $validated['rate_per_order'];
        }

        // Changing a vehicle type's payment method orphans every log recorded under the old one:
        // switching a contract from `zones` to `zones_tiers` left 962 orders with no zone, and so
        // with no price, and nothing said so. The switch is still allowed - it is no longer silent.
        // `dry_run` lets the UI ask what a change would cost before committing to it.
        $pricingImpact = $this->driverPricingChangeImpact($contract, $validated['driver_pricing_rules'] ?? null);

        if ($request->boolean('dry_run')) {
            return response()->json([
                'dry_run' => true,
                'pricing_change_impact' => $pricingImpact,
            ]);
        }

        $contract->update($validated);

        return response()->json(
            $contract->fresh()->toArray() + ['pricing_change_impact' => $pricingImpact]
        );
    }

    /**
     * What a change of driver payment method would do to logs already recorded.
     *
     * Zone-priced methods need `daily_logs.zone` (or a zone_orders map in notes); a log written
     * under a method with no notion of zones carries neither, so once the method changes those
     * orders match no zone rule and are worth nothing.
     *
     * @param  array<int|string, mixed>|null  $newRules
     * @return array<int, array<string, mixed>>
     */
    /**
     * A pricing rule has to say how it bills and carry the figures that method needs.
     *
     * Payroll and revenue both read the method out of each vehicle type's own rule, and a rule that
     * omitted it used to be read as «zones» by default: a rule stating a perfectly good monthly
     * amount was priced by zones it had none of, billed nothing, and said nothing about why. The
     * contract's own payment-method column is not what either engine reads, so a column saying
     * «fixed» over rules saying «zones» produced a screen and a figure that disagreed.
     *
     * @param  mixed  $rules
     * @return array<int, string> one message per problem found, empty when the rules are usable
     */
    private function pricingRuleProblems($rules, ?string $declaredMethod, string $side): array
    {
        $label = $side === 'client' ? 'العميل' : 'السائق';

        if (! is_array($rules) || $rules === []) {
            return ["لا توجد قواعد تسعير {$label} — لا يمكن احتساب المستحقات بدونها."];
        }

        $problems = [];

        foreach ($rules as $vehicleType => $rule) {
            $where = "تسعير {$label}، نوع المركبة {$vehicleType}";

            if (! is_array($rule)) {
                $problems[] = "{$where}: القاعدة غير صالحة.";

                continue;
            }

            $method = $rule['payment_method'] ?? null;
            if ($method === null || $method === '') {
                $problems[] = "{$where}: لم تُحدَّد طريقة الاحتساب.";

                continue;
            }

            if ($declaredMethod !== null && $method !== $declaredMethod) {
                $problems[] = "{$where}: القاعدة تقول «{$method}» وطريقة الدفع المحددة للعقد «{$declaredMethod}» — لا يجوز أن تختلفا.";

                continue;
            }

            $problems = array_merge($problems, $this->methodDataProblems($method, $rule, $where));
        }

        return $problems;
    }

    /**
     * @param  array<string, mixed>  $rule
     * @return array<int, string>
     */
    private function methodDataProblems(string $method, array $rule, string $where): array
    {
        $positive = fn ($v) => is_numeric($v) && (float) $v > 0;

        if ($method === 'fixed' || $method === 'hybrid') {
            $amountKey = $method === 'hybrid' && array_key_exists('hybrid_fixed', $rule) ? 'hybrid_fixed' : 'fixed_amount';
            if (! $positive($rule[$amountKey] ?? null)) {
                return ["{$where}: المبلغ الشهري مطلوب ويجب أن يكون أكبر من صفر."];
            }

            // The hybrid half that is paid per order still has to be priced.
            if ($method === 'hybrid' && array_key_exists('hybrid_tiers', $rule)) {
                return $this->bandProblems($rule['hybrid_tiers'], $where);
            }

            return [];
        }

        if ($method === 'tiers') {
            return $this->bandProblems($rule['tiers'] ?? null, $where);
        }

        if ($method === 'zones' || $method === 'zones_tiers') {
            $zones = $rule[$method === 'zones' ? 'zones' : 'zones_tiers'] ?? null;
            if (! is_array($zones) || $zones === []) {
                return ["{$where}: لم تُحدَّد أي فئة."];
            }

            $problems = [];
            foreach ($zones as $zone) {
                if (! is_array($zone)) {
                    $problems[] = "{$where}: فئة غير صالحة.";

                    continue;
                }
                $name = $zone['name'] ?? $zone['id'] ?? '؟';
                if ($method === 'zones') {
                    if (! $positive($zone['price'] ?? $zone['rate'] ?? null)) {
                        $problems[] = "{$where}: الفئة ({$name}) بلا سعر.";
                    }
                } else {
                    $problems = array_merge($problems, $this->bandProblems($zone['tiers'] ?? null, "{$where}، الفئة ({$name})"));
                }
            }

            return $problems;
        }

        return ["{$where}: طريقة احتساب غير معروفة ({$method})."];
    }

    /**
     * @param  mixed  $bands
     * @return array<int, string>
     */
    private function bandProblems($bands, string $where): array
    {
        if (! is_array($bands) || $bands === []) {
            return ["{$where}: لم تُحدَّد أي شريحة."];
        }

        foreach ($bands as $band) {
            if (! is_array($band) || ! is_numeric($band['price'] ?? null) || (float) $band['price'] <= 0) {
                return ["{$where}: شريحة بلا سعر."];
            }
        }

        return [];
    }

    private function driverPricingChangeImpact(Contract $contract, $newRules): array
    {
        if (! is_array($newRules)) {
            return [];
        }

        $oldRules = is_array($contract->driver_pricing_rules) ? $contract->driver_pricing_rules : [];
        $zoneBased = ['zones', 'zone', 'zones_tiers'];
        $impact = [];

        foreach ($newRules as $vtId => $rule) {
            $newMethod = is_array($rule) ? ($rule['payment_method'] ?? null) : null;
            $oldMethod = $oldRules[$vtId]['payment_method'] ?? null;
            if (! $newMethod || $newMethod === $oldMethod) {
                continue;
            }

            $entry = [
                'vehicle_type_id' => (int) $vtId,
                'from' => $oldMethod,
                'to' => $newMethod,
                'logs_left_unpriced' => 0,
                'orders_left_unpriced' => 0,
            ];

            if (in_array($newMethod, $zoneBased, true) && ! in_array($oldMethod, $zoneBased, true)) {
                $unzoned = DailyLog::withoutGlobalScopes()
                    ->whereNull('deleted_at')
                    ->where('contract_id', $contract->id)
                    ->where('orders_count', '>', 0)
                    ->where(function ($q) {
                        $q->whereNull('zone')->orWhere('zone', '');
                    })
                    ->where(function ($q) {
                        $q->whereNull('notes')->orWhere('notes', 'not like', '%zone_orders%');
                    })
                    ->get(['id', 'orders_count']);

                $entry['logs_left_unpriced'] = $unzoned->count();
                $entry['orders_left_unpriced'] = (int) $unzoned->sum('orders_count');
            }

            $impact[] = $entry;
        }

        return $impact;
    }

    public function deletionCheck(Contract $contract): JsonResponse
    {
        $blocks = $contract->getDeletionBlocks();

        return response()->json([
            'is_deletable' => empty($blocks),
            'blocks' => $blocks,
        ]);
    }

    public function destroy(Request $request, Contract $contract): JsonResponse
    {
        if (! $request->user()->can('contracts.delete')) {
            return response()->json(['message' => 'غير مصرح لك بحذف العقود.'], 403);
        }

        $blocks = $contract->getDeletionBlocks();
        if (! empty($blocks)) {
            return response()->json([
                'message' => 'لا يمكن حذف العقد لوجود ارتباطات نشطة أو قيود تشغيلية.',
                'errors' => $blocks,
            ], 422);
        }

        $contract->delete();

        return response()->json(['message' => 'Contract deleted.']);
    }

    public function lock(Contract $contract): JsonResponse
    {
        if ($contract->is_locked) {
            return response()->json(['message' => 'Contract is already locked.'], 422);
        }

        $contract->update(['is_locked' => true]);

        return response()->json(['message' => 'Contract locked successfully. Financial data is now immutable.']);
    }

    public function storeMonthlyParameter(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'year' => 'required|integer|min:2020|max:2030',
            'month' => 'required|integer|min:1|max:12',
            'min_valid_days' => 'nullable|integer|min:0',
            'min_completed_orders' => 'nullable|integer|min:0',
            'daily_active_time_percentage' => 'nullable|numeric|min:0|max:100',
            'daily_min_orders' => 'nullable|integer|min:0',
            'capacity_incentive_rules' => 'nullable|array',
            'experience_incentive_rules' => 'nullable|array',
            'mandatory_days' => 'nullable|array',
            'mandatory_days.*.start_date' => 'required|date',
            'mandatory_days.*.end_date' => 'required|date|after_or_equal:mandatory_days.*.start_date',
            'mandatory_days.*.min_required_days' => 'required|integer|min:1',
        ]);

        \DB::beginTransaction();
        try {
            $param = ContractMonthlyParameter::updateOrCreate([
                'contract_id' => $contract->id,
                'year' => $validated['year'],
                'month' => $validated['month'],
            ], [
                'company_id' => $contract->company_id,
                'min_valid_days' => $validated['min_valid_days'] ?? null,
                'min_completed_orders' => $validated['min_completed_orders'] ?? null,
                'daily_active_time_percentage' => $validated['daily_active_time_percentage'] ?? null,
                'daily_min_orders' => $validated['daily_min_orders'] ?? null,
                'capacity_incentive_rules' => $validated['capacity_incentive_rules'] ?? null,
                'experience_incentive_rules' => $validated['experience_incentive_rules'] ?? null,
            ]);

            // Clear and recreate mandatory days
            $param->mandatoryDays()->delete();
            if (! empty($validated['mandatory_days'])) {
                foreach ($validated['mandatory_days'] as $md) {
                    $param->mandatoryDays()->create($md);
                }
            }

            \DB::commit();

            return response()->json($param->load('mandatoryDays'), 200);
        } catch (\Throwable $e) {
            \DB::rollBack();

            return response()->json(['message' => 'حدث خطأ أثناء حفظ الإعدادات: '.$e->getMessage()], 500);
        }
    }

    public function storeBonus(Request $request, Contract $contract): JsonResponse
    {
        $validated = $request->validate([
            'bonus_name' => 'required|string|max:255',
            'amount' => 'required|numeric|min:0',
            'is_valid_drivers_only' => 'required|boolean',
        ]);

        $validated['company_id'] = $contract->company_id;
        $bonus = $contract->bonuses()->create($validated);

        return response()->json($bonus, 201);
    }

    public function destroyBonus(Contract $contract, $bonusId): JsonResponse
    {
        $bonus = $contract->bonuses()->findOrFail($bonusId);
        $bonus->delete();

        return response()->json(['message' => 'تم حذف الحافز بنجاح.']);
    }
}
