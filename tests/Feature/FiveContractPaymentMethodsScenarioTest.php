<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\ContractPayrollAdjustment;
use App\Models\CustodyItem;
use App\Models\DailyLog;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\LeaveType;
use App\Models\MaintenanceRecord;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Violation;
use App\Services\ContractRevenueService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One contract per driver payment method, each pricing two vehicle types, each carrying the same
 * three shapes of driver — a full month on one vehicle type, a full month split across two, and a
 * driver assigned to only part of the month — plus an override attempt for every other payment
 * method.
 *
 * The matrix exists to pin two things at once: that a month is priced whatever the method and
 * however the vehicle changed under the driver, and that a zone-based override is refused on a
 * contract whose client is not billed by zone. Zones are the client's own map; a driver cannot be
 * paid against a map the contract never drew.
 */
class FiveContractPaymentMethodsScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const YEAR = 2026;

    private const MONTH = 5;

    /** Small car, large car, bike — every contract below prices exactly two of them. */
    private const TYPE_BIKE = 1;

    private const TYPE_SMALL = 2;

    private const TYPE_LARGE = 3;

    private Company $company;

    private User $user;

    private Client $client;

    /** @var array<int, Vehicle> keyed by vehicle type id */
    private array $vehicles = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Matrix Co',
            'code' => 'matrixco',
            'enabled_modules' => Company::DEFAULT_MODULES,
            'is_active' => true,
        ]);

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::create([
            'name' => 'Matrix Admin',
            'email' => 'admin@matrix.test',
            'password' => bcrypt('password'),
            'role' => 'admin',
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->client = Client::create(['name' => 'Matrix Client', 'company_id' => $this->company->id]);

        foreach ([
            self::TYPE_BIKE => ['Motorcycle', 'سيكل'],
            self::TYPE_SMALL => ['Small Car', 'سيارة صغيرة'],
            self::TYPE_LARGE => ['Large Car', 'سيارة كبيرة'],
        ] as $id => [$name, $nameAr]) {
            // `id` is not fillable, and the pricing rules are keyed by vehicle type id.
            \DB::table('vehicle_types')->updateOrInsert(['id' => $id], [
                'company_id' => $this->company->id,
                'name' => $name,
                'name_ar' => $nameAr,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->vehicles[$id] = Vehicle::create([
                'plate_number' => 'V-MX-'.$id,
                'make' => 'Fleet',
                'status' => 'working',
                'company_id' => $this->company->id,
                'vehicle_type_id' => $id,
            ]);
        }

        $this->actingAs($this->user);
    }

    /**
     * The five contracts. A zone-based driver method needs a zone-based client, so the two
     * zone contracts bill their client by zone and the other three do not — which is what makes
     * the override matrix below meaningful.
     *
     * @return array<string, array{client: string, driver: string, types: array{int, int}}>
     */
    private function contractMatrix(): array
    {
        return [
            'fixed' => ['client' => 'fixed', 'driver' => 'fixed', 'types' => [self::TYPE_BIKE, self::TYPE_SMALL]],
            'tiers' => ['client' => 'tiers', 'driver' => 'tiers', 'types' => [self::TYPE_SMALL, self::TYPE_LARGE]],
            'hybrid' => ['client' => 'hybrid', 'driver' => 'hybrid', 'types' => [self::TYPE_BIKE, self::TYPE_LARGE]],
            'zones' => ['client' => 'zones', 'driver' => 'zones', 'types' => [self::TYPE_BIKE, self::TYPE_SMALL]],
            'zones_tiers' => ['client' => 'zones', 'driver' => 'zones_tiers', 'types' => [self::TYPE_SMALL, self::TYPE_LARGE]],
        ];
    }

    /**
     * What the CLIENT is billed — a separate question from what the driver is paid, and previously
     * left as a placeholder on every contract, so three of the five billed nothing at all and every
     * financial screen read the month as a loss.
     *
     * Each contract prices only the two vehicle types it runs. The third type a driver strays onto
     * has no rule, which is how an unbillable order is meant to read. Month volumes are 3,850 orders
     * on the first type (Z1 1,935 · Z2 1,875 · 40 carrying no zone) and 130 on the second (Z1 65 ·
     * Z2 65).
     *
     * @return array<string, array<string, mixed>>
     */
    private function clientRules(string $key, int $typeA, int $typeB): array
    {
        // Some tests build a variant contract under a suffixed key («fixed-mid»); it bills the same
        // way its matrix entry does.
        $key = explode('-', $key)[0];

        return match ($key) {
            // A flat monthly fee per vehicle type; the order count does not enter it.
            'fixed' => [
                (string) $typeA => ['payment_method' => 'fixed', 'fixed_amount' => 900],
                (string) $typeB => ['payment_method' => 'fixed', 'fixed_amount' => 600],
            ],

            // One band for the whole month's volume on that type, so the 3,850-order type falls
            // into the second band while the 130-order type stays in the first.
            'tiers' => [
                (string) $typeA => ['payment_method' => 'tiers', 'tiers' => [
                    ['min' => 1, 'max' => 1000, 'price' => 0.400],
                    ['min' => 1001, 'max' => null, 'price' => 0.250],
                ]],
                (string) $typeB => ['payment_method' => 'tiers', 'tiers' => [
                    ['min' => 1, 'max' => 200, 'price' => 0.500],
                    ['min' => 201, 'max' => null, 'price' => 0.300],
                ]],
            ],

            // Hybrid bills the client the same flat way; the hybrid part is the driver's side.
            'hybrid' => [
                (string) $typeA => ['payment_method' => 'hybrid', 'fixed_amount' => 750],
                (string) $typeB => ['payment_method' => 'hybrid', 'fixed_amount' => 450],
            ],

            // Priced per zone. Orders carrying no zone are billed nothing and reported as such.
            'zones' => [
                (string) $typeA => ['payment_method' => 'zones', 'zones' => [
                    ['id' => 'Z1', 'name' => 'شمال', 'price' => 0.300],
                    ['id' => 'Z2', 'name' => 'جنوب', 'price' => 0.200],
                ]],
                (string) $typeB => ['payment_method' => 'zones', 'zones' => [
                    ['id' => 'Z1', 'name' => 'شمال', 'price' => 0.250],
                    ['id' => 'Z2', 'name' => 'جنوب', 'price' => 0.150],
                ]],
            ],

            // The same, except the south zone is deliberately missing from the first type's rules:
            // a zone the drivers actually worked and the client agreement never priced.
            'zones_tiers' => [
                (string) $typeA => ['payment_method' => 'zones', 'zones' => [
                    ['id' => 'Z1', 'name' => 'شمال', 'price' => 0.220],
                ]],
                (string) $typeB => ['payment_method' => 'zones', 'zones' => [
                    ['id' => 'Z1', 'name' => 'شمال', 'price' => 0.400],
                    ['id' => 'Z2', 'name' => 'جنوب', 'price' => 0.400],
                ]],
            ],
        };
    }

    /**
     * What each contract must bill for May, worked out by hand from the rules above and the month's
     * own order counts.
     *
     * A contract's twenty drivers log 3,930 orders between them: 3,750 on the first vehicle type
     * (Z1 1,885 · Z2 1,825 · 40 carrying no zone), 130 on the second (Z1 65 · Z2 65), and 50 on a
     * third type the contract does not price at all.
     *
     * @return array{revenue: float, unpriced: int}
     */
    private function expectedClientBilling(string $key): array
    {
        return match ($key) {
            // 900 + 600. Orders are irrelevant to a flat fee; only the unpriced type is reported.
            'fixed' => ['revenue' => 1500.000, 'unpriced' => 50],

            // 3,750 × 0.250 (second band) + 130 × 0.500 (first band) = 937.500 + 65.000.
            'tiers' => ['revenue' => 1002.500, 'unpriced' => 50],

            // 750 + 450.
            'hybrid' => ['revenue' => 1200.000, 'unpriced' => 50],

            // (1,885 × 0.300 + 1,825 × 0.200) + (65 × 0.250 + 65 × 0.150)
            //   = (565.500 + 365.000) + (16.250 + 9.750) = 930.500 + 26.000.
            // Unpriced: 40 orders carrying no zone, plus 50 on the type with no rule.
            'zones' => ['revenue' => 956.500, 'unpriced' => 90],

            // 1,885 × 0.220 + (65 × 0.400 + 65 × 0.400) = 414.700 + 52.000.
            // Unpriced: 1,825 in the unpriced south zone, 40 with no zone, 50 on the unpriced type.
            'zones_tiers' => ['revenue' => 466.700, 'unpriced' => 1915],
        };
    }

    /** A priced rule of the given method, deliberately cheaper on the second vehicle type. */
    private function driverRule(string $method, bool $second): array
    {
        $f = $second ? 0.8 : 1.0;

        return match ($method) {
            'fixed' => [
                'payment_method' => 'fixed',
                'fixed_amount' => round(260 * $f, 3),
                'fixed_target' => 0,
            ],
            'tiers' => [
                'payment_method' => 'tiers',
                'tiers' => [
                    ['min' => 1, 'max' => 100, 'price' => round(0.500 * $f, 3)],
                    ['min' => 101, 'max' => null, 'price' => round(0.900 * $f, 3)],
                ],
            ],
            'hybrid' => [
                'payment_method' => 'hybrid',
                'hybrid_fixed' => round(120 * $f, 3),
                'hybrid_tiers' => [
                    ['min' => 1, 'max' => null, 'price' => round(0.300 * $f, 3)],
                ],
            ],
            'zones' => [
                'payment_method' => 'zones',
                'zones' => [
                    ['id' => 'Z1', 'name' => 'شمال', 'price' => round(0.400 * $f, 3)],
                    ['id' => 'Z2', 'name' => 'جنوب', 'price' => round(0.600 * $f, 3)],
                ],
            ],
            // Note the shape: zones_tiers keys its rules under `zones_tiers`, and each zone carries
            // its own `tiers` rather than a flat `price`. A contract configured with the `zones`
            // shape by mistake is paid 0.000 with no error at all.
            'zones_tiers' => [
                'payment_method' => 'zones_tiers',
                'zones_tiers' => [
                    ['id' => 'Z1', 'name' => 'شمال', 'tiers' => [
                        ['min' => 1, 'max' => 100, 'price' => round(0.450 * $f, 3)],
                        ['min' => 101, 'max' => null, 'price' => round(0.700 * $f, 3)],
                    ]],
                    ['id' => 'Z2', 'name' => 'جنوب', 'tiers' => [
                        ['min' => 1, 'max' => 100, 'price' => round(0.650 * $f, 3)],
                        ['min' => 101, 'max' => null, 'price' => round(0.900 * $f, 3)],
                    ]],
                ],
            ],
        };
    }

    private function makeContract(string $key, array $spec): Contract
    {
        [$typeA, $typeB] = $spec['types'];

        return Contract::create([
            'client_id' => $this->client->id,
            'contract_number' => 'CON-MX-'.strtoupper($key),
            'name' => 'عقد '.$key,
            'payment_type' => 'per_order',
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'client_payment_method' => $spec['client'],
            'driver_payment_method' => $spec['driver'],
            'company_id' => $this->company->id,
            'currency' => 'KWD',
            'default_required_work_days' => 26,
            'default_absence_divisor' => 26,
            'is_validity_enabled' => false,
            'client_pricing_rules' => $this->clientRules($key, $typeA, $typeB),
            'driver_pricing_rules' => [
                (string) $typeA => $this->driverRule($spec['driver'], false),
                (string) $typeB => $this->driverRule($spec['driver'], true),
            ],
        ]);
    }

    private function makeDriver(string $label): Employee
    {
        return Employee::create([
            'name' => 'سائق '.$label,
            'employee_number' => 'EMP-'.strtoupper(substr(md5($label), 0, 8)),
            'company_id' => $this->company->id,
            'status' => 'active',
            'role_category' => 'driver',
            'date_of_joining' => '2026-01-01',
            'actual_salary' => 0.000,
        ]);
    }

    private function assign(Employee $driver, Contract $contract, string $from, ?string $to): ContractAssignment
    {
        return ContractAssignment::create([
            'employee_id' => $driver->id,
            'contract_id' => $contract->id,
            'start_date' => $from,
            'end_date' => $to,
            'status' => 'active',
            'company_id' => $this->company->id,
        ]);
    }

    /** A worked day, with its orders split across two zones so a zone contract has something to price. */
    private function workDay(Employee $driver, Contract $contract, int $vehicleType, int $day, int $orders, ?int $month = null): void
    {
        $north = intdiv($orders, 2);

        DailyLog::create([
            'employee_id' => $driver->id,
            'contract_id' => $contract->id,
            'vehicle_id' => $this->vehicles[$vehicleType]->id,
            'log_date' => sprintf('%04d-%02d-%02d', self::YEAR, $month ?? self::MONTH, $day),
            'driver_status' => 'working',
            'orders_count' => $orders,
            'notes' => json_encode(['zone_orders' => ['Z1' => $north, 'Z2' => $orders - $north]]),
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);
    }

    private function sheet(Contract $contract): array
    {
        return $this->getJson(
            "/api/payroll/contract-sheet/{$contract->id}?year=".self::YEAR.'&month='.self::MONTH
        )->assertOk()->json();
    }

    private function rowFor(array $sheet, Employee $driver): array
    {
        $row = collect($sheet['drivers'] ?? [])->firstWhere('employee_id', $driver->id);
        $this->assertNotNull($row, "driver {$driver->name} missing from the sheet");

        return $row;
    }

    /**
     * Every contract carries the same sixteen drivers, so each payment method is put through every
     * situation rather than only the one that happened to be tested against it. Twelve of them work
     * and are paid; the last four exist to attempt an override in each of the other four methods.
     *
     * @return array<string, mixed>
     */
    private function buildContract(string $key, array $spec): array
    {
        [$typeA, $typeB] = $spec['types'];
        $unpricedType = collect([self::TYPE_BIKE, self::TYPE_SMALL, self::TYPE_LARGE])
            ->diff($spec['types'])->first();
        $contract = $this->makeContract($key, $spec);
        $built = ['contract' => $contract, 'unpriced_type' => $unpricedType];

        $hire = function (string $label, string $from = '2026-05-01', ?string $to = '2026-05-31') use ($contract, $key) {
            $driver = $this->makeDriver("{$label}-{$key}");

            return [$driver, $this->assign($driver, $contract, $from, $to)];
        };

        // 1. A full month's attendance — the contract's whole 26 working days — on one vehicle
        //    type. His figure must come out as the salary the contract names.
        [$built['steady']] = $hire('ثابت');
        foreach (range(1, 26) as $day) {
            $this->workDay($built['steady'], $contract, $typeA, $day, 10);
        }

        // 2. The same attendance and the same orders, but half of it on each vehicle type.
        [$built['switched']] = $hire('نوعين');
        foreach (range(1, 13) as $day) {
            $this->workDay($built['switched'], $contract, $typeA, $day, 10);
        }
        foreach (range(14, 26) as $day) {
            $this->workDay($built['switched'], $contract, $typeB, $day, 10);
        }

        // 3. Assigned to part of the contract only.
        [$built['partial']] = $hire('جزئي', '2026-05-16');
        foreach (range(16, 23) as $day) {
            $this->workDay($built['partial'], $contract, $typeA, $day, 10);
        }

        // 4. Worked all 31 days against a contract that pays 26: the salary caps, the orders do not.
        [$built['overtime']] = $hire('كامل-الشهر');
        foreach (range(1, 31) as $day) {
            $this->workDay($built['overtime'], $contract, $typeA, $day, 10);
        }

        // 5. Ten days on a priced vehicle, five on a type this contract has no rule for.
        [$built['unpriced']] = $hire('نوع-غير-مسعّر');
        foreach (range(1, 10) as $day) {
            $this->workDay($built['unpriced'], $contract, $typeA, $day, 10);
        }
        foreach (range(12, 16) as $day) {
            $this->workDay($built['unpriced'], $contract, $unpricedType, $day, 10);
        }

        // 6. Assigned and never worked a day. He must still appear, or a balance would vanish with him.
        [$built['idle']] = $hire('بلا-عمل');

        // 7. Thirteen days on the contract's own pricing, thirteen under a fixed override.
        [$built['overridden'], $assignment] = $hire('استثناء-جزئي');
        foreach (range(1, 26) as $day) {
            $this->workDay($built['overridden'], $contract, $typeA, $day, 10);
        }
        $this->postJson("/api/contract-assignments/{$assignment->id}/overrides", [
            'override_type' => 'fixed',
            'fixed_amount' => 240,
            'fixed_target' => 0,
            'customization_reason' => 'اتفاق للنصف الثاني من الشهر',
            'effective_from' => '2026-05-14',
            'effective_to' => '2026-05-31',
        ])->assertSuccessful();

        // 8. A full month's work and a fine larger than it.
        [$built['indebted']] = $hire('مدين');
        foreach (range(1, 26) as $day) {
            $this->workDay($built['indebted'], $contract, $typeA, $day, 10);
        }
        Violation::create([
            'company_id' => $this->company->id,
            'employee_id' => $built['indebted']->id,
            'vehicle_id' => $this->vehicles[$typeA]->id,
            'created_by' => $this->user->id,
            'violation_date' => '2026-05-10',
            'violation_type' => 'تجاوز السرعة',
            'amount' => 400.000,
            'driver_deduction' => 400.000,
            'driver_share' => 400.000,
            'contract_share' => 0.000,
            'is_driver_liable' => true,
            'is_deducted' => false,
        ]);

        // 9. Thirteen days here; the caller adds his other contract.
        [$built['multiContract']] = $hire('عقدين');
        foreach (range(1, 13) as $day) {
            $this->workDay($built['multiContract'], $contract, $typeA, $day, 10);
        }

        // 10. Ten days where only six of each day's ten orders carry a zone.
        [$built['unzoned']] = $hire('بلا-فئة');
        foreach (range(1, 10) as $day) {
            DailyLog::create([
                'employee_id' => $built['unzoned']->id,
                'contract_id' => $contract->id,
                'vehicle_id' => $this->vehicles[$typeA]->id,
                'log_date' => sprintf('2026-05-%02d', $day),
                'driver_status' => 'working',
                'orders_count' => 10,
                'notes' => json_encode(['zone_orders' => ['Z1' => 6]]),
                'company_id' => $this->company->id,
                'created_by' => $this->user->id,
            ]);
        }

        // 11. Took the vehicle over on the 15th, the day the previous holder gave it up.
        [$built['handover']] = $hire('مستلم');
        foreach (range(1, 10) as $day) {
            $this->workDay($built['handover'], $contract, $typeA, $day, 10);
        }
        \DB::table('vehicle_assignments')->insert([
            ['company_id' => $this->company->id, 'vehicle_id' => $this->vehicles[$typeA]->id,
                'employee_id' => $built['steady']->id, 'contract_id' => $contract->id,
                'assigned_date' => '2026-05-01', 'unassigned_date' => '2026-05-15',
                'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
            ['company_id' => $this->company->id, 'vehicle_id' => $this->vehicles[$typeA]->id,
                'employee_id' => $built['handover']->id, 'contract_id' => $contract->id,
                'assigned_date' => '2026-05-15', 'unassigned_date' => null,
                'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 12. A full month's work carrying one of every deduction the system can raise.
        [$built['allDeductions']] = $hire('كل-الخصوم');
        foreach (range(1, 26) as $day) {
            $this->workDay($built['allDeductions'], $contract, $typeA, $day, 10);
        }
        // Deliberately left to the loop at the end, which gives this set to every driver.

        // 13. Missed his target. The deficit is not a deduction record — it is taken inside the
        //     pricing itself, so it never reaches the deductions ledger and never shows as a line
        //     among the fines and advances. It is carried on a per-driver target override so the
        //     rest of the contract's drivers keep their own figures.
        [$built['deficient'], $assignment] = $hire('عاجز-عن-الهدف');
        foreach (range(1, 26) as $day) {
            $this->workDay($built['deficient'], $contract, $typeA, $day, 10);
        }
        $this->postJson("/api/contract-assignments/{$assignment->id}/overrides", [
            'override_type' => 'fixed',
            'fixed_amount' => 260,
            'fixed_target' => 300,
            'fixed_deficit_rate' => 0.100,
            'customization_reason' => 'راتب بهدف شهري',
            'effective_from' => '2026-05-01',
            'effective_to' => '2026-05-31',
        ])->assertSuccessful();

        // 14. Beat the same target — the mirror of the deficit, which adds money instead.
        [$built['surplus'], $assignment] = $hire('متجاوز-الهدف');
        foreach (range(1, 26) as $day) {
            $this->workDay($built['surplus'], $contract, $typeA, $day, 10);
        }
        $this->postJson("/api/contract-assignments/{$assignment->id}/overrides", [
            'override_type' => 'fixed',
            'fixed_amount' => 260,
            'fixed_target' => 200,
            'fixed_deficit_rate' => 0.100,
            'customization_reason' => 'راتب بهدف شهري منخفض',
            'effective_from' => '2026-05-01',
            'effective_to' => '2026-05-31',
        ])->assertSuccessful();

        // 15. Charges the driver shares with the company. Only his share may reach his pay.
        [$built['splitCharges']] = $hire('حصص-مقسومة');
        foreach (range(1, 10) as $day) {
            $this->workDay($built['splitCharges'], $contract, $typeA, $day, 10);
        }
        Violation::create([
            'company_id' => $this->company->id, 'employee_id' => $built['splitCharges']->id,
            'vehicle_id' => $this->vehicles[$typeA]->id, 'created_by' => $this->user->id,
            'violation_date' => '2026-05-08', 'violation_type' => 'مخالفة مقسومة',
            'amount' => 100.000, 'driver_deduction' => 40.000, 'driver_share' => 40.000,
            'contract_share' => 60.000, 'charge_contract_id' => $contract->id,
            'is_driver_liable' => true, 'is_deducted' => false,
        ]);
        DriverExpense::create([
            'employee_id' => $built['splitCharges']->id, 'vehicle_id' => $this->vehicles[$typeA]->id,
            'expense_type' => 'tyres', 'amount' => 30.000, 'driver_amount' => 12.000,
            'company_amount' => 18.000, 'borne_by' => 'split', 'expense_date' => '2026-05-09',
            'is_deducted' => false, 'company_id' => $this->company->id,
        ]);
        MaintenanceRecord::create([
            'vehicle_id' => $this->vehicles[$typeA]->id, 'maintenance_type' => 'accident',
            'maintenance_date' => '2026-05-09', 'status' => 'approved', 'actual_cost' => 200.000,
            'is_driver_liable' => true, 'liable_employee_id' => $built['splitCharges']->id,
            'driver_bearing_percentage' => 40, 'company_bearing_percentage' => 60,
            'driver_deduction' => 80.000, 'reported_by' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        // 16. Every charge against him belongs to the company. Not one fils may reach his pay.
        [$built['companyCharges']] = $hire('على-الشركة');
        foreach (range(1, 10) as $day) {
            $this->workDay($built['companyCharges'], $contract, $typeA, $day, 10);
        }
        Violation::create([
            'company_id' => $this->company->id, 'employee_id' => $built['companyCharges']->id,
            'vehicle_id' => $this->vehicles[$typeA]->id, 'created_by' => $this->user->id,
            'violation_date' => '2026-05-08', 'violation_type' => 'مخالفة على الشركة',
            'amount' => 100.000, 'driver_deduction' => 0.000, 'driver_share' => 0.000,
            'contract_share' => 100.000, 'charge_contract_id' => $contract->id,
            'is_driver_liable' => false, 'is_deducted' => false,
        ]);
        DriverExpense::create([
            'employee_id' => $built['companyCharges']->id, 'vehicle_id' => $this->vehicles[$typeA]->id,
            'expense_type' => 'oil', 'amount' => 30.000, 'driver_amount' => 0.000,
            'company_amount' => 30.000, 'borne_by' => 'company', 'expense_date' => '2026-05-09',
            'is_deducted' => false, 'company_id' => $this->company->id,
        ]);
        MaintenanceRecord::create([
            'vehicle_id' => $this->vehicles[$typeA]->id, 'maintenance_type' => 'periodic',
            'maintenance_date' => '2026-05-10', 'status' => 'approved', 'actual_cost' => 90.000,
            'is_driver_liable' => false, 'liable_employee_id' => $built['companyCharges']->id,
            'driver_deduction' => 0.000, 'reported_by' => $this->user->id,
            'company_id' => $this->company->id,
        ]);
        CustodyItem::create([
            'employee_id' => $built['companyCharges']->id, 'item_type' => 'clothing',
            'item_description' => 'زي', 'value' => 25.000, 'issued_date' => '2026-04-01',
            'returned_date' => '2026-05-11', 'status' => 'returned', 'return_condition' => 'good',
            'deduction_amount' => 0.000, 'issued_by' => $this->user->id,
            'company_id' => $this->company->id,
        ]);
        $paidLeave = LeaveType::firstOrCreate(
            ['company_id' => $this->company->id, 'name' => 'Paid Leave'],
            ['name_ar' => 'إجازة مدفوعة', 'is_paid' => true]
        );
        EmployeeLeave::create([
            'employee_id' => $built['companyCharges']->id, 'leave_type_id' => $paidLeave->id,
            'start_date' => '2026-05-20', 'end_date' => '2026-05-21', 'days_count' => 2,
            'status' => 'approved', 'is_paid' => true, 'total_deduction' => 0.000,
            'company_id' => $this->company->id,
        ]);

        // 17-20. A driver paid by each of the OTHER four payment methods, through an override.
        //
        // This is what makes every contract exercise all five methods rather than only its own:
        // a fixed-salary contract still has a driver on tiers, one on hybrid, and — where its
        // client is billed by zone — one on zones and one on zone tiers. They work a full month
        // like anybody else and are paid by the method their override names. Where the override is
        // refused, which is the case for a zone method on a client not billed by zone, the driver
        // simply stays on the contract's own pricing.
        $built['overrideDrivers'] = [];
        foreach ($this->otherMethods($spec['driver']) as $method) {
            [$driver, $assignment] = $hire("طريقة-{$method}");
            foreach (range(1, 26) as $day) {
                $this->workDay($driver, $contract, $typeA, $day, 10);
            }

            // The zone check reads the driver's live vehicle assignment to find the client's rule.
            \DB::table('vehicle_assignments')->insert([
                'company_id' => $this->company->id,
                'vehicle_id' => $this->vehicles[$typeA]->id,
                'employee_id' => $driver->id,
                'contract_id' => $contract->id,
                'assigned_date' => '2026-05-01',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $response = $this->postJson(
                "/api/contract-assignments/{$assignment->id}/overrides",
                $this->overridePayload($method)
            );

            $built['overrideDrivers'][$method] = [
                'driver' => $driver,
                'assignment' => $assignment,
                'accepted' => $response->status() < 300,
                'response' => $response,
            ];
        }

        // Every driver carries one of every deduction type, so each situation above is checked
        // against the money as well as the pricing: a driver who never worked still owes what he
        // owes, a half-priced month is still charged in full, and a frozen month must show the
        // same figures back.
        foreach ($this->workingShapes() as $shape) {
            $this->attachEveryDeduction($built[$shape], $contract, $typeA);
        }
        foreach ($built['overrideDrivers'] as $entry) {
            $this->attachEveryDeduction($entry['driver'], $contract, $typeA);
        }

        return $built;
    }

    /** One of every deduction type the system can raise, on a single driver in a single month. */
    private function attachEveryDeduction(Employee $driver, Contract $contract, int $vehicleType): void
    {
        $vehicle = $this->vehicles[$vehicleType];

        Violation::create([
            'company_id' => $this->company->id, 'employee_id' => $driver->id, 'vehicle_id' => $vehicle->id,
            'created_by' => $this->user->id, 'violation_date' => '2026-05-10', 'violation_type' => 'سرعة',
            'amount' => 20.000, 'driver_deduction' => 20.000, 'driver_share' => 20.000, 'contract_share' => 0.000,
            // The violations screen resolves the contract the driver was on that day and stores it,
            // and payroll now honours it, so the fixture has to carry it too.
            'charge_contract_id' => $contract->id,
            'is_driver_liable' => true, 'is_deducted' => false,
        ]);

        MaintenanceRecord::create([
            'vehicle_id' => $vehicle->id, 'maintenance_type' => 'repair', 'maintenance_date' => '2026-05-11',
            'status' => 'approved', 'is_driver_liable' => true, 'liable_employee_id' => $driver->id,
            'driver_deduction' => 15.000, 'reported_by' => $this->user->id, 'company_id' => $this->company->id,
        ]);

        CustodyItem::create([
            'employee_id' => $driver->id, 'item_type' => 'phone', 'item_description' => 'جهاز',
            'value' => 60.000, 'issued_date' => '2026-04-01', 'returned_date' => '2026-05-12',
            'status' => 'returned', 'return_condition' => 'damaged', 'deduction_amount' => 10.000,
            'issued_by' => $this->user->id, 'company_id' => $this->company->id,
        ]);

        DriverExpense::create([
            'employee_id' => $driver->id, 'vehicle_id' => $vehicle->id, 'expense_type' => 'fuel',
            'amount' => 8.000, 'driver_amount' => 8.000, 'company_amount' => 0, 'borne_by' => 'driver',
            'expense_date' => '2026-05-13', 'is_deducted' => false, 'company_id' => $this->company->id,
        ]);

        SalaryAdvance::create([
            'employee_id' => $driver->id, 'company_id' => $this->company->id, 'amount' => 60.000,
            'monthly_installment' => 20.000, 'total_installments' => 3, 'paid_installments' => 0,
            'remaining_balance' => 60.000, 'advance_date' => '2026-05-01', 'status' => 'active',
            'approved_by' => $this->user->id,
        ]);

        $leaveType = LeaveType::firstOrCreate(
            ['company_id' => $this->company->id, 'name' => 'Unpaid Leave'],
            ['name_ar' => 'إجازة بدون راتب', 'is_paid' => false]
        );
        EmployeeLeave::create([
            'employee_id' => $driver->id, 'leave_type_id' => $leaveType->id,
            'start_date' => '2026-05-28', 'end_date' => '2026-05-29', 'days_count' => 2,
            'status' => 'approved', 'is_paid' => false, 'total_deduction' => 12.000,
            'company_id' => $this->company->id,
        ]);

        ContractPayrollAdjustment::create([
            'company_id' => $this->company->id, 'contract_id' => $contract->id,
            'employee_id' => $driver->id, 'year' => self::YEAR, 'month' => self::MONTH,
            'type' => 'deduction', 'amount' => 5.000, 'reason' => 'تسوية يدوية',
            'created_by' => $this->user->id,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════
    // The three driver shapes, across all five payment methods
    // ══════════════════════════════════════════════════════════════════════

    /**
     * What each pattern of days must pay under each method, worked out by hand from the contract's
     * own rules — not read back from the system. Every worked day carries ten orders, split evenly
     * between the two zones. The contract pays 26 working days and prices its second vehicle type
     * at 80% of the first.
     *
     *   full26A      26 days on type A                    260 orders
     *   split13      13 days A + 13 days B                130 + 130 — the same attendance as full26A
     *   late8A       assigned from the 16th, 8 days on A   80 orders
     *   all31A       every one of the month's 31 days      310 orders, salary capped at 26
     *   ten10A       10 days on A                         100 orders
     *   thirteen13A  13 days on A                         130 orders
     *   unzoned10A   10 days on A, only 6 of each 10 orders carrying a zone
     *
     * @return array<string, array<string, float>>
     */
    private function expectedByPattern(): array
    {
        return [
            // 260 ÷ 26 = 10.000/day on type A, 208 ÷ 26 = 8.000/day on type B.
            'fixed' => [
                'full26A' => 260.000, 'split13' => 234.000, 'late8A' => 80.000, 'all31A' => 260.000,
                'ten10A' => 100.000, 'thirteen13A' => 130.000, 'unzoned10A' => 100.000, 'zero' => 0.000,
            ],
            // A: 0.500 to 100 orders then 0.900. B: 0.400 then 0.720.
            'tiers' => [
                'full26A' => 234.000, 'split13' => 210.600, 'late8A' => 40.000, 'all31A' => 279.000,
                'ten10A' => 50.000, 'thirteen13A' => 117.000, 'unzoned10A' => 50.000, 'zero' => 0.000,
            ],
            // Base 120 ÷ 26 = 4.615/day plus 0.300/order on A; 96 ÷ 26 = 3.692 plus 0.240 on B.
            // Each vehicle's stretch is rounded to the fils before the two are added.
            'hybrid' => [
                'full26A' => 198.000, 'split13' => 178.200, 'late8A' => 60.923, 'all31A' => 213.000,
                'ten10A' => 76.154, 'thirteen13A' => 99.000, 'unzoned10A' => 76.154, 'zero' => 0.000,
            ],
            // A: 0.400 north + 0.600 south. B: 0.320 and 0.480. Unzoned orders are never priced.
            'zones' => [
                'full26A' => 130.000, 'split13' => 117.000, 'late8A' => 40.000, 'all31A' => 155.000,
                'ten10A' => 50.000, 'thirteen13A' => 65.000, 'unzoned10A' => 24.000, 'zero' => 0.000,
            ],
            // The band is chosen by the orders run in that zone on that vehicle: 130 through a zone
            // reaches the second band, 65 does not — which is the owner's rule expressed in money.
            'zones_tiers' => [
                'full26A' => 208.000, 'split13' => 128.700, 'late8A' => 44.000, 'all31A' => 248.000,
                'ten10A' => 55.000, 'thirteen13A' => 71.500, 'unzoned10A' => 27.000, 'zero' => 0.000,
            ],
        ];
    }

    /**
     * Which pattern each of the twelve working drivers follows. `overridden` is the one exception:
     * thirteen days on the contract's pricing, then thirteen at 240 ÷ 26 = 120.000 under a fixed
     * override.
     *
     * @return array<string, string>
     */
    private function driverPatterns(): array
    {
        return [
            'steady' => 'full26A',
            'switched' => 'split13',
            'partial' => 'late8A',
            'overtime' => 'all31A',
            'unpriced' => 'ten10A',
            'idle' => 'zero',
            'indebted' => 'full26A',
            'multiContract' => 'thirteen13A',
            'unzoned' => 'unzoned10A',
            'handover' => 'ten10A',
            'allDeductions' => 'full26A',
            'splitCharges' => 'ten10A',
            'companyCharges' => 'ten10A',
        ];
    }

    private function expectedGross(string $contractKey, string $shape): float
    {
        $table = $this->expectedByPattern()[$contractKey];

        return match ($shape) {
            // Thirteen days on the contract's pricing, then thirteen at 240 ÷ 26 = 120.000.
            'overridden' => round($table['thirteen13A'] + 120.000, 3),

            // 260.000 earned, then 40 orders short of a 300 target at 0.100 each.
            'deficient' => 256.000,

            // 260.000 earned, then 60 orders past a 200 target at the same rate.
            'surplus' => 266.000,

            default => $table[$this->driverPatterns()[$shape]],
        };
    }

    /** @return string[] the sixteen drivers who work and are paid */
    private function workingShapes(): array
    {
        return array_merge(array_keys($this->driverPatterns()), ['overridden', 'deficient', 'surplus']);
    }

    /**
     * Every driver carries the same baseline set: a 20.000 fine, 15.000 of maintenance, a 10.000
     * custody charge, an 8.000 expense and a 20.000 advance instalment — 73.000 of company-level
     * charges — plus a 5.000 manual deduction at contract level.
     *
     * He also carries two days of approved unpaid leave, which is deliberately NOT in that total:
     * he is paid for the days he worked, so those two days already cost him their pay.
     *
     * Three of them carry more on top, and one of those three must be charged nothing extra.
     */
    private function expectedPendingDeductions(string $shape): float
    {
        return match ($shape) {
            // The 400.000 fine that puts him under.
            'indebted' => 473.000,

            // 40 of a 100.000 fine, 12 of a 30.000 expense, 80 of a 200.000 repair at 40%.
            'splitCharges' => 205.000,

            // A fine, an expense, a repair, an intact custody item and a paid leave, every one of
            // them the company's. The baseline is all that may reach him.
            'companyCharges' => 73.000,

            default => 73.000,
        };
    }

    /**
     * What a driver paid by an override earns for a full 26-day month of 260 orders, worked out by
     * hand from the override's own terms. All five are exercised on every contract, so each method
     * is checked against four contracts it does not belong to as well as the one it does.
     *
     *   fixed        240 ÷ 26 = 9.231/day × 26                        = 240.000
     *   tiers        260 orders at a flat 0.550                       = 143.000
     *   hybrid       130 ÷ 26 × 26 = 130.000, plus 260 × 0.350         = 221.000
     *   zones        only the north zone is priced: 130 × 0.420        =  54.600
     *   zones_tiers  the north zone's only band: 130 × 0.470           =  61.100
     *
     * A refused override leaves the driver on the contract's own pricing for a full month.
     */
    private function expectedOverrideGross(string $contractKey, string $method, bool $accepted): float
    {
        if (! $accepted) {
            return $this->expectedByPattern()[$contractKey]['full26A'];
        }

        return match ($method) {
            'fixed' => 240.000,
            'tiers' => 143.000,
            'hybrid' => 221.000,
            'zones' => 54.600,
            'zones_tiers' => 61.100,
        };
    }

    /** What the contract sheet itself deducts — traffic fines only, at contract level. */
    private function expectedContractFines(string $shape): float
    {
        return match ($shape) {
            'indebted' => 420.000,
            'splitCharges' => 60.000,
            default => 20.000,
        };
    }

    // ══════════════════════════════════════════════════════════════════════
    // Every driver, every method, on both sheets
    // ══════════════════════════════════════════════════════════════════════

    public function test_the_contract_sheet_pays_every_driver_the_figure_the_rules_call_for(): void
    {
        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);
            $sheet = $this->sheet($built['contract']);

            $this->assertCount(20, $sheet['drivers'] ?? [], "contract {$key} should list all twenty drivers");

            foreach ($this->workingShapes() as $shape) {
                $row = $this->rowFor($sheet, $built[$shape]);

                $this->assertSame(
                    $this->expectedGross($key, $shape),
                    round((float) $row['gross_contract_earnings'], 3),
                    "contract {$key}, driver «{$shape}»: wrong gross on the contract sheet"
                );
            }

            // The contract sheet applies exactly two deductions, and both belong to this contract:
            // the fines charged to it, and the manual adjustments agreed on it. Everything else a
            // driver owes is a charge against the person and is settled once on the consolidated
            // sheet — so each is asserted as its own column here, not just folded into the net.
            foreach ($this->workingShapes() as $shape) {
                $row = $this->rowFor($sheet, $built[$shape]);

                $this->assertSame(
                    $this->expectedContractFines($shape),
                    round((float) $row['violations_deduction'], 3),
                    "contract {$key}, driver «{$shape}»: wrong fines on the contract sheet"
                );

                $this->assertSame(
                    -5.000,
                    round((float) $row['manual_adjustments']['total'], 3),
                    "contract {$key}, driver «{$shape}»: wrong manual adjustment on the contract sheet"
                );

                // Nothing else may reach it: no advance instalment, no repair, no custody, no
                // expense. Those are the person's, and the contract sheet must not touch them.
                $this->assertSame(
                    round($this->expectedGross($key, $shape) - $this->expectedContractFines($shape) - 5.000, 3),
                    round((float) $row['net_payout'], 3),
                    "contract {$key}, driver «{$shape}»: wrong net on the contract sheet"
                );
            }
        }
    }

    public function test_every_driver_carries_his_own_deductions_whatever_his_situation(): void
    {
        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);
            $this->postJson("/api/payroll/contract-sheet/{$built['contract']->id}/approve", [
                'year' => self::YEAR, 'month' => self::MONTH,
            ])->assertOk();

            $sheet = $this->getJson('/api/payroll/consolidated/'.self::YEAR.'/'.self::MONTH)->assertOk()->json();

            foreach ($this->workingShapes() as $shape) {
                $row = collect($sheet['drivers'])->firstWhere('employee_id', $built[$shape]->id);
                $this->assertNotNull($row, "contract {$key}, «{$shape}» missing from the consolidated sheet");

                $this->assertSame(
                    $this->expectedPendingDeductions($shape),
                    round((float) $row['pending_deductions_total'], 3),
                    "contract {$key}, driver «{$shape}»: wrong deductions"
                );

                // Every driver also carries the same 5.000 manual deduction.
                $this->assertSame(
                    -5.000,
                    round((float) $row['manual_adjustments_total'], 3),
                    "contract {$key}, driver «{$shape}»: wrong manual adjustment"
                );
            }

            // A driver who never worked a day still owes what he owes, and the sheet says so.
            $idle = collect($sheet['drivers'])->firstWhere('employee_id', $built['idle']->id);
            $this->assertSame(0.000, round((float) $idle['gross_contract_earnings'], 3), "contract {$key}");
            $this->assertSame(73.000, round((float) $idle['pending_deductions_total'], 3), "contract {$key}");

            $this->postJson("/api/payroll/contract-sheet/{$built['contract']->id}/unapprove", [
                'year' => self::YEAR, 'month' => self::MONTH,
            ]);
        }
    }

    public function test_the_consolidated_sheet_carries_the_same_figures_through_approval(): void
    {
        $built = [];

        foreach ($this->contractMatrix() as $key => $spec) {
            $built[$key] = $this->buildContract($key, $spec);
            $this->postJson("/api/payroll/contract-sheet/{$built[$key]['contract']->id}/approve", [
                'year' => self::YEAR, 'month' => self::MONTH,
            ])->assertOk();
        }

        $draft = $this->getJson('/api/payroll/consolidated/'.self::YEAR.'/'.self::MONTH)->assertOk()->json();
        $this->assertFalse((bool) $draft['is_approved']);

        foreach ($this->contractMatrix() as $key => $spec) {
            foreach ($this->workingShapes() as $shape) {
                $row = collect($draft['drivers'])->firstWhere('employee_id', $built[$key][$shape]->id);
                $this->assertNotNull($row, "contract {$key}, «{$shape}» missing from the consolidated sheet");

                $this->assertSame(
                    $this->expectedGross($key, $shape),
                    round((float) $row['gross_contract_earnings'], 3),
                    "contract {$key}, «{$shape}»: the consolidated gross must match the contract sheet"
                );
            }
        }

        // The frozen month serves the same figures back from its snapshot rather than recomputing:
        // a driver's pay must not move because the month was closed.
        $this->postJson('/api/payroll/consolidated/'.self::YEAR.'/'.self::MONTH.'/approve')->assertOk();
        $approved = $this->getJson('/api/payroll/consolidated/'.self::YEAR.'/'.self::MONTH)->assertOk()->json();
        $this->assertTrue((bool) $approved['is_approved']);

        foreach ($this->contractMatrix() as $key => $spec) {
            foreach ($this->workingShapes() as $shape) {
                $row = collect($approved['drivers'])->firstWhere('employee_id', $built[$key][$shape]->id);
                $this->assertSame(
                    $this->expectedGross($key, $shape),
                    round((float) $row['gross_contract_earnings'], 3),
                    "contract {$key}, «{$shape}»: approving the month changed the figure"
                );
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Each situation, read on every contract
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_full_month_on_two_vehicle_types_is_paid_less_than_the_same_month_on_one(): void
    {
        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);
            $sheet = $this->sheet($built['contract']);

            $steady = $this->rowFor($sheet, $built['steady']);
            $switched = $this->rowFor($sheet, $built['switched']);

            // Identical attendance, identical orders — the only difference is the vehicle.
            $this->assertSame((int) $steady['orders_count'], (int) $switched['orders_count'], "contract {$key}");
            $this->assertTrue((bool) $switched['vehicle_type_is_mixed'], "contract {$key}");
            $this->assertFalse((bool) $steady['vehicle_type_is_mixed'], "contract {$key}");

            $this->assertLessThan(
                (float) $steady['gross_contract_earnings'],
                (float) $switched['gross_contract_earnings'],
                "contract {$key}: half the month on the cheaper vehicle must pay less"
            );
        }
    }

    public function test_the_salary_caps_at_the_contracts_working_days_but_the_orders_do_not(): void
    {
        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);
            $sheet = $this->sheet($built['contract']);

            $overtime = $this->rowFor($sheet, $built['overtime']);
            $this->assertSame(310, (int) $overtime['orders_count'], "contract {$key}: all 31 days are counted");

            if ($key === 'fixed') {
                // A pure salary stops at the days the contract buys.
                $this->assertSame(260.000, round((float) $overtime['gross_contract_earnings'], 3));
            } else {
                // Everything order-driven keeps paying past the 26th day.
                $this->assertGreaterThan(
                    (float) $this->rowFor($sheet, $built['steady'])['gross_contract_earnings'],
                    (float) $overtime['gross_contract_earnings'],
                    "contract {$key}: extra orders must still earn"
                );
            }
        }
    }

    public function test_a_partial_assignment_is_paid_only_for_its_own_days(): void
    {
        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);
            $sheet = $this->sheet($built['contract']);

            $this->assertSame(80, (int) $this->rowFor($sheet, $built['partial'])['orders_count'], "contract {$key}");
            $this->assertSame(260, (int) $this->rowFor($sheet, $built['steady'])['orders_count'], "contract {$key}");
        }
    }

    public function test_a_vehicle_type_the_contract_does_not_price_earns_nothing_and_is_flagged(): void
    {
        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);
            $row = $this->rowFor($this->sheet($built['contract']), $built['unpriced']);

            $this->assertTrue((bool) $row['unresolved_vehicle_type'], "contract {$key}: the unpriced type must be flagged");
            $this->assertSame(150, (int) $row['orders_count'], "contract {$key}: every order is still counted");

            // Only the ten priced days are paid; the five on the unpriced type earn nothing.
            $this->assertSame(
                $this->expectedGross($key, 'unpriced'),
                round((float) $row['gross_contract_earnings'], 3),
                "contract {$key}: the priced days must still be paid in full"
            );
        }
    }

    public function test_a_driver_with_no_work_still_appears_on_the_sheet(): void
    {
        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);
            $row = $this->rowFor($this->sheet($built['contract']), $built['idle']);

            $this->assertSame(0, (int) $row['orders_count'], "contract {$key}");
            $this->assertSame(0.000, round((float) $row['gross_contract_earnings'], 3), "contract {$key}");
        }
    }

    public function test_orders_with_no_zone_are_reported_and_never_priced_at_another_zones_rate(): void
    {
        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);
            $row = $this->rowFor($this->sheet($built['contract']), $built['unzoned']);

            $this->assertSame(100, (int) $row['orders_count'], "contract {$key}: every order is counted");
            $this->assertSame(
                $this->expectedGross($key, 'unzoned'),
                round((float) $row['gross_contract_earnings'], 3),
                "contract {$key}: only the zoned orders may be priced"
            );

            if (in_array($key, ['zones', 'zones_tiers'], true)) {
                $unpriced = collect($row['calculation_details'] ?? [])->where('is_unpriced', true)->sum('orders');
                $this->assertSame(40, (int) $unpriced, "contract {$key}: the forty unzoned orders must be named");
            }
        }
    }

    public function test_an_override_covering_half_the_month_splits_the_pricing(): void
    {
        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);
            $row = $this->rowFor($this->sheet($built['contract']), $built['overridden']);

            $this->assertSame(
                $this->expectedGross($key, 'overridden'),
                round((float) $row['gross_contract_earnings'], 3),
                "contract {$key}: thirteen days on the contract plus thirteen on the override"
            );

            $labels = collect($row['calculation_details'] ?? [])->pluck('label')->implode(' | ');
            $this->assertStringContainsString('استثناء مخصص', $labels, "contract {$key}");
            $this->assertStringContainsString('تسعير العقد', $labels, "contract {$key}");
        }
    }

    public function test_a_month_whose_deductions_exceed_the_earnings_goes_negative_and_says_so(): void
    {
        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);
            $row = $this->rowFor($this->sheet($built['contract']), $built['indebted']);

            $this->assertSame(420.000, round((float) $row['violations_deduction'], 3), "contract {$key}");
            $this->assertLessThan(0.0, (float) $row['net_payout'], "contract {$key}: the net must be allowed to go negative");

            // The earnings themselves are untouched — only the net goes under.
            $this->assertSame(
                $this->expectedGross($key, 'indebted'),
                round((float) $row['gross_contract_earnings'], 3),
                "contract {$key}"
            );
        }
    }

    public function test_all_seven_deduction_types_land_on_one_month_together(): void
    {
        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);
            $this->postJson("/api/payroll/contract-sheet/{$built['contract']->id}/approve", [
                'year' => self::YEAR, 'month' => self::MONTH,
            ])->assertOk();

            $sheet = $this->getJson('/api/payroll/consolidated/'.self::YEAR.'/'.self::MONTH)->assertOk()->json();
            $row = collect($sheet['drivers'])->firstWhere('employee_id', $built['allDeductions']->id);

            // 20 fine + 15 maintenance + 10 custody + 8 expense + 20 instalment. The unpaid leave is
            // deliberately not among them: the driver was already unpaid for those days.
            $this->assertSame(73.000, round((float) $row['pending_deductions_total'], 3), "contract {$key}");
            $this->assertSame(-5.000, round((float) $row['manual_adjustments_total'], 3), "contract {$key}");

            $this->postJson('/api/payroll/consolidated/'.self::YEAR.'/'.self::MONTH.'/unapprove');
            $this->postJson("/api/payroll/contract-sheet/{$built['contract']->id}/unapprove", [
                'year' => self::YEAR, 'month' => self::MONTH,
            ]);
        }
    }

    /**
     * The other half of every contract: what the CLIENT is billed. The driver side is priced from
     * driver_pricing_rules and the client side from client_pricing_rules, and until now only the
     * driver side was ever asserted — so a contract could bill nothing at all and every test still
     * passed while the profitability screen showed the whole month as a loss.
     */
    public function test_every_contract_bills_its_client_what_its_own_rules_call_for(): void
    {
        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);
            $expected = $this->expectedClientBilling($key);

            $dashboard = $this->getJson(
                "/api/contracts/{$built['contract']->id}/dashboard?year=".self::YEAR.'&month='.self::MONTH
            )->assertOk()->json();

            $this->assertSame(
                $expected['revenue'],
                round((float) $dashboard['financials']['actual']['revenue'], 3),
                "contract {$key}: wrong client revenue"
            );

            // Every contract bills something. A zero here is the defect this test exists to catch.
            $this->assertGreaterThan(
                0.0,
                (float) $dashboard['financials']['actual']['revenue'],
                "contract {$key}: a contract that bills nothing reads as a total loss on every screen"
            );
        }
    }

    /**
     * An order the client agreement does not cover must never be given an invented price. Three
     * ways that happens, and all three are in this scenario: a vehicle type with no rule at all, a
     * zone the drivers worked that the agreement never priced, and orders logged with no zone.
     */
    public function test_orders_the_client_agreement_does_not_cover_are_reported_never_priced(): void
    {
        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);
            $expected = $this->expectedClientBilling($key);

            $revenue = ContractRevenueService::forContractMonth(
                $built['contract'],
                DailyLog::withoutGlobalScopes()
                    ->where('contract_id', $built['contract']->id)
                    ->whereBetween('log_date', ['2026-05-01', '2026-05-31'])
                    ->with('vehicle')
                    ->get()
            );

            $this->assertSame(
                $expected['unpriced'],
                (int) $revenue['unpriced_orders'],
                "contract {$key}: wrong count of orders the client is not billed for"
            );

            $this->assertSame(
                $expected['revenue'],
                round((float) $revenue['revenue'], 3),
                "contract {$key}: the service and the screen must agree"
            );

            // Whatever could not be priced is named, not silently dropped.
            $unpricedLines = array_filter($revenue['details'], fn ($d) => $d['is_unpriced'] ?? false);
            if ($expected['unpriced'] > 0) {
                $this->assertNotEmpty($unpricedLines, "contract {$key}: unbillable orders with nothing said about them");
                foreach ($unpricedLines as $line) {
                    $this->assertSame(0.0, round((float) $line['amount'], 3), "contract {$key}: an unpriced line billed money");
                }
            }

            $this->assertSame(
                3930,
                (int) $revenue['orders'],
                "contract {$key}: every order in the month must be accounted for, priced or not"
            );
        }
    }

    public function test_a_driver_on_two_contracts_is_charged_a_fine_only_once(): void
    {
        $matrix = $this->contractMatrix();
        $built = [];
        foreach ($matrix as $key => $spec) {
            $built[$key] = $this->buildContract($key, $spec);
        }

        // Each contract's «two contracts» driver picks up a second contract as well.
        $keys = array_keys($matrix);
        foreach ($keys as $i => $key) {
            $other = $keys[($i + 1) % count($keys)];
            $driver = $built[$key]['multiContract'];
            $this->assign($driver, $built[$other]['contract'], '2026-05-01', '2026-05-31');
            foreach (range(15, 24) as $day) {
                $this->workDay($driver, $built[$other]['contract'], $matrix[$other]['types'][0], $day, 10);
            }

            // Raised on the second contract and charged to it, the way the violations screen
            // attributes a fine to whichever contract the driver was on that day.
            Violation::create([
                'company_id' => $this->company->id, 'employee_id' => $driver->id,
                'vehicle_id' => $this->vehicles[$matrix[$other]['types'][0]]->id, 'created_by' => $this->user->id,
                'violation_date' => '2026-05-16', 'violation_type' => 'وقوف ممنوع',
                'amount' => 30.000, 'driver_deduction' => 30.000, 'driver_share' => 30.000,
                'contract_share' => 0.000, 'charge_contract_id' => $built[$other]['contract']->id,
                'is_driver_liable' => true, 'is_deducted' => false,
            ]);

            // A manual adjustment is agreed with one contract and belongs to it alone. He already
            // carries 5.000 on his home contract; this is a second, different one on the other, so
            // the two can be told apart on the two sheets instead of both reading 5.000.
            ContractPayrollAdjustment::create([
                'company_id' => $this->company->id, 'contract_id' => $built[$other]['contract']->id,
                'employee_id' => $driver->id, 'year' => self::YEAR, 'month' => self::MONTH,
                'type' => 'deduction', 'amount' => 7.000, 'reason' => 'تسوية على العقد الثاني',
                'created_by' => $this->user->id,
            ]);
        }

        foreach ($built as $b) {
            $this->postJson("/api/payroll/contract-sheet/{$b['contract']->id}/approve", [
                'year' => self::YEAR, 'month' => self::MONTH,
            ])->assertOk();
        }
        $this->postJson('/api/payroll/consolidated/'.self::YEAR.'/'.self::MONTH.'/approve')->assertOk();

        $sheet = $this->getJson('/api/payroll/consolidated/'.self::YEAR.'/'.self::MONTH)->assertOk()->json();

        foreach ($keys as $i => $key) {
            $other = $keys[($i + 1) % count($keys)];
            $driver = $built[$key]['multiContract'];

            $row = collect($sheet['drivers'])->firstWhere('employee_id', $driver->id);
            $this->assertNotNull($row, "contract {$key}");
            $this->assertSame(
                // His own 20.000 baseline fine plus the 30.000 raised here — each counted once.
                50.000,
                round((float) $row['violations_deduction'], 3),
                "contract {$key}: the fine must not be doubled across the driver's two contracts"
            );

            // The consolidated sheet was always right. The per-contract sheets were not: they took
            // every fine the driver had that month off him on BOTH, ignoring the contract each fine
            // was charged to, so the two together took 100.000 from a driver who owed 50.000.
            $onHome = $this->rowFor($this->sheet($built[$key]['contract']), $driver);
            $onOther = $this->rowFor($this->sheet($built[$other]['contract']), $driver);

            $this->assertSame(
                20.000,
                round((float) $onHome['violations_deduction'], 3),
                "contract {$key}: its own sheet takes only the fine charged to it"
            );
            $this->assertSame(
                30.000,
                round((float) $onOther['violations_deduction'], 3),
                "contract {$key}: the second sheet takes only the fine raised there"
            );
            $this->assertSame(
                50.000,
                round((float) $onHome['violations_deduction'] + (float) $onOther['violations_deduction'], 3),
                "contract {$key}: the two sheets together take exactly what he owes, not double"
            );

            // The same question for the other value that belongs to a contract rather than to the
            // person: a manual adjustment agreed on one contract must not appear on the other.
            $this->assertSame(
                -5.000,
                round((float) $onHome['manual_adjustments']['total'], 3),
                "contract {$key}: its own sheet carries only its own adjustment"
            );
            $this->assertSame(
                -7.000,
                round((float) $onOther['manual_adjustments']['total'], 3),
                "contract {$key}: the second sheet carries only the adjustment agreed there"
            );

            // And the person's sheet adds the two up rather than picking one or doubling either.
            $this->assertSame(
                -12.000,
                round((float) $row['manual_adjustments_total'], 3),
                "contract {$key}: the consolidated sheet sums both contracts' adjustments"
            );
        }
    }

    /**
     * A charge can be split between the driver and the company, or belong to the company outright.
     * Only the driver's share may ever reach his pay.
     */
    public function test_only_the_drivers_own_share_of_a_split_charge_is_taken_from_him(): void
    {
        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);
            $this->postJson("/api/payroll/contract-sheet/{$built['contract']->id}/approve", [
                'year' => self::YEAR, 'month' => self::MONTH,
            ])->assertOk();

            $sheet = $this->getJson('/api/payroll/consolidated/'.self::YEAR.'/'.self::MONTH)->assertOk()->json();

            // 40 of a 100.000 fine, 12 of a 30.000 expense, 80 of a 200.000 repair at 40%.
            $split = collect($sheet['drivers'])->firstWhere('employee_id', $built['splitCharges']->id);
            $this->assertSame(205.000, round((float) $split['pending_deductions_total'], 3), "contract {$key}");
            $this->assertSame(60.000, round((float) $split['pending_violations_deduction'], 3), "contract {$key}: only his share of the fine");

            // A fine, an expense, a repair, a custody item returned intact and a paid leave — every
            // one of them the company's. Nothing at all may reach him.
            $company = collect($sheet['drivers'])->firstWhere('employee_id', $built['companyCharges']->id);
            $this->assertSame(73.000, round((float) $company['pending_deductions_total'], 3), "contract {$key}");
            $this->assertSame(
                // His pay less only the 5.000 manual deduction every driver carries. Not one
                // fils of the company-borne fine, expense, repair, custody or leave reaches him.
                round($this->expectedGross($key, 'companyCharges') - 5.000, 3),
                round((float) $company['final_net_payout'], 3),
                "contract {$key}: his pay is untouched by the company-borne charges"
            );

            $this->postJson("/api/payroll/contract-sheet/{$built['contract']->id}/unapprove", [
                'year' => self::YEAR, 'month' => self::MONTH,
            ]);
        }
    }

    /**
     * The company's share is stored on the fine — `contract_share`, and `charge_contract_id` naming
     * which contract bears it — but nothing reads either one for money. The contract dashboard
     * derives its own figure as `amount − driver_deduction` and attributes it by driver and vehicle
     * instead; the vehicle report counts only fines marked wholly company-liable, so a split fine
     * contributes nothing to it at all. One 100.000 fine split 40/60 is therefore a 60.000 cost on
     * one screen and 0.000 on another, and the stored 60.000 is read by neither.
     *
     * This pins the current behaviour so the day the attribution is built, it is built visibly.
     */
    public function test_the_company_share_of_a_fine_is_stored_but_never_charged_to_its_contract(): void
    {
        $spec = $this->contractMatrix()['fixed'];
        $built = $this->buildContract('fixed', $spec);

        $fine = Violation::withoutGlobalScopes()
            ->where('employee_id', $built['splitCharges']->id)
            ->where('violation_type', 'مخالفة مقسومة')
            ->first();

        $this->assertSame(60.000, round((float) $fine->contract_share, 3), 'the share is recorded');
        $this->assertSame($built['contract']->id, (int) $fine->charge_contract_id, 'and so is the contract that bears it');

        // Nothing in the payroll path consumes either column: the driver's sheet shows only his
        // own 40.000, and the contract is never charged the 60.000 anywhere it is read back.
        $row = $this->rowFor($this->sheet($built['contract']), $built['splitCharges']);
        $this->assertSame(60.000, round((float) $row['violations_deduction'], 3));
    }

    public function test_a_handover_day_is_claimed_by_both_drivers_and_must_not_be_guessed(): void
    {
        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);

            $holders = \DB::table('vehicle_assignments')
                ->where('vehicle_id', $this->vehicles[$spec['types'][0]]->id)
                ->where('contract_id', $built['contract']->id)
                ->whereDate('assigned_date', '<=', '2026-05-15')
                ->where(fn ($q) => $q->whereNull('unassigned_date')->orWhereDate('unassigned_date', '>=', '2026-05-15'))
                ->whereIn('employee_id', [$built['steady']->id, $built['handover']->id])
                ->pluck('employee_id');

            // The outgoing assignment ends on the same date the incoming one begins, so both cover
            // handover day and the system picks one without telling anyone. Until assignments carry
            // a time of day this is unresolvable in principle — it must warn, not choose. This pins
            // the ambiguity so the fix has something to break.
            $this->assertCount(2, $holders, "contract {$key}: handover day is claimed by both drivers");
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // The override matrix
    // ══════════════════════════════════════════════════════════════════════

    /** The other four methods, for a contract whose own method is $own. */
    private function otherMethods(string $own): array
    {
        return array_values(array_diff(['fixed', 'tiers', 'hybrid', 'zones', 'zones_tiers'], [$own]));
    }

    private function overridePayload(string $method): array
    {
        $base = [
            'override_type' => $method,
            'customization_reason' => 'اتفاق خاص مع السائق',
            'effective_from' => '2026-05-01',
            'effective_to' => '2026-05-31',
        ];

        return $base + match ($method) {
            'fixed' => ['fixed_amount' => 240, 'fixed_target' => 0],
            'tiers' => ['tiers' => [['min' => 1, 'max' => null, 'price' => 0.550]]],
            'hybrid' => ['hybrid_fixed' => 130, 'hybrid_tiers' => [['min' => 1, 'max' => null, 'price' => 0.350]]],
            'zones' => ['zones' => [['id' => 'Z1', 'name' => 'شمال', 'price' => 0.420]]],
            'zones_tiers' => ['zones_tiers' => [
                ['id' => 'Z1', 'name' => 'شمال', 'tiers' => [['min' => 1, 'max' => null, 'price' => 0.470]]],
            ]],
        };
    }

    /**
     * The point of the override drivers: every contract pays somebody by every method, so each of
     * the five is exercised against four contracts it does not belong to as well as its own.
     */
    public function test_every_contract_pays_a_driver_by_each_of_the_other_four_methods(): void
    {
        $paid = [];

        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);
            $sheet = $this->sheet($built['contract']);

            foreach ($built['overrideDrivers'] as $method => $entry) {
                $row = $this->rowFor($sheet, $entry['driver']);

                $this->assertSame(
                    260,
                    (int) $row['orders_count'],
                    "contract {$key}, «{$method}» override: he worked a full month like anybody else"
                );

                $this->assertSame(
                    $this->expectedOverrideGross($key, $method, $entry['accepted']),
                    round((float) $row['gross_contract_earnings'], 3),
                    "contract {$key}: a driver overridden to «{$method}» is paid by that method"
                );

                $paid[$method] = ($paid[$method] ?? 0) + 1;
            }
        }

        // Four of the five methods appear on each of the five contracts.
        foreach (['fixed', 'tiers', 'hybrid', 'zones', 'zones_tiers'] as $method) {
            $this->assertSame(4, $paid[$method] ?? 0, "«{$method}» should be overridden onto four contracts");
        }
    }

    public function test_a_zone_override_is_refused_when_the_client_is_not_billed_by_zone(): void
    {
        $refused = 0;
        $allowed = 0;

        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);
            $clientIsZoned = $spec['client'] === 'zones';

            foreach ($built['overrideDrivers'] as $method => $entry) {
                $zoneBased = in_array($method, ['zones', 'zones_tiers'], true);

                if ($zoneBased && ! $clientIsZoned) {
                    $entry['response']->assertStatus(422);
                    $this->assertStringContainsString(
                        'الفئات',
                        (string) $entry['response']->json('message'),
                        "contract {$key}: a {$method} override must be refused, and must say why"
                    );
                    $this->assertFalse($entry['accepted']);
                    $refused++;
                } else {
                    $entry['response']->assertSuccessful();
                    $this->assertTrue($entry['accepted']);
                    $allowed++;
                }
            }

            // And a refused override changes nothing: the driver keeps the contract's own pricing.
            $sheet = $this->sheet($built['contract']);
            foreach ($built['overrideDrivers'] as $method => $entry) {
                if ($entry['accepted']) {
                    continue;
                }
                $this->assertSame(
                    $this->expectedByPattern()[$key]['full26A'],
                    round((float) $this->rowFor($sheet, $entry['driver'])['gross_contract_earnings'], 3),
                    "contract {$key}: a refused «{$method}» override must leave him on the contract's pricing"
                );
            }
        }

        // Three contracts bill their client by something other than zone; each refuses two of its
        // four override methods. The remaining fourteen are legitimate.
        $this->assertSame(6, $refused, 'six zone overrides should have been refused');
        $this->assertSame(14, $allowed, 'every other override should have been accepted');
    }

    public function test_a_zone_driver_method_is_refused_on_the_contract_itself(): void
    {
        foreach (['fixed', 'tiers', 'hybrid'] as $clientMethod) {
            foreach (['zones', 'zones_tiers'] as $driverMethod) {
                $this->postJson('/api/contracts', [
                    'client_id' => $this->client->id,
                    'contract_number' => "CON-BAD-{$clientMethod}-{$driverMethod}",
                    'name' => 'عقد غير متوافق',
                    'start_date' => '2026-05-01',
                    'end_date' => '2026-05-31',
                    'client_payment_method' => $clientMethod,
                    'driver_payment_method' => $driverMethod,
                ])->assertStatus(422)->assertJsonValidationErrors('driver_payment_method');
            }
        }
    }

    // ══════════════════════════════════════════════════════════════════════
    // Contract and month boundaries
    // ══════════════════════════════════════════════════════════════════════

    public function test_a_contract_starting_mid_month_pays_only_from_its_start(): void
    {
        foreach ($this->contractMatrix() as $key => $spec) {
            $contract = $this->makeContract($key.'-mid', $spec);
            $contract->update(['start_date' => '2026-05-15']);

            $driver = $this->makeDriver("عقد-نصّي-{$key}");
            $this->assign($driver, $contract, '2026-05-16', '2026-05-31');
            foreach (range(16, 23) as $day) {
                $this->workDay($driver, $contract, $spec['types'][0], $day, 10);
            }

            $row = $this->rowFor($this->sheet($contract), $driver);

            $this->assertSame(80, (int) $row['orders_count'], "contract {$key}");
            $this->assertSame(
                $this->expectedByPattern()[$key]['late8A'],
                round((float) $row['gross_contract_earnings'], 3),
                "contract {$key}: a contract that starts mid-month pays its own days"
            );
        }
    }

    public function test_a_month_survives_being_reopened_and_approved_again(): void
    {
        foreach ($this->contractMatrix() as $key => $spec) {
            $built = $this->buildContract($key, $spec);
            $driver = $built['allDeductions'];

            $this->postJson("/api/payroll/contract-sheet/{$built['contract']->id}/approve", [
                'year' => self::YEAR, 'month' => self::MONTH,
            ])->assertOk();

            $balance = fn () => (float) SalaryAdvance::withoutGlobalScopes()
                ->where('employee_id', $driver->id)->value('remaining_balance');

            $this->postJson('/api/payroll/consolidated/2026/5/approve')->assertOk();
            $this->assertSame(40.0, $balance(), "contract {$key}: one instalment collected");

            $this->postJson('/api/payroll/consolidated/2026/5/unapprove')->assertOk();
            $this->assertSame(60.0, $balance(), "contract {$key}: reopening hands the instalment back");

            $this->postJson('/api/payroll/consolidated/2026/5/approve')->assertOk();
            $this->assertSame(40.0, $balance(), "contract {$key}: approving again takes it once, not twice");

            $this->postJson('/api/payroll/consolidated/2026/5/unapprove')->assertOk();
            $this->postJson("/api/payroll/contract-sheet/{$built['contract']->id}/unapprove", [
                'year' => self::YEAR, 'month' => self::MONTH,
            ]);
        }
    }

    public function test_months_can_be_closed_out_of_calendar_order(): void
    {
        $spec = $this->contractMatrix()['fixed'];
        $built = $this->buildContract('fixed', $spec);
        $built['contract']->update(['end_date' => '2026-06-30']);

        foreach (range(1, 5) as $day) {
            $this->workDay($built['steady'], $built['contract'], $spec['types'][0], $day, 10, 6);
        }

        foreach ([[2026, 6], [2026, 5]] as [$y, $m]) {
            $this->postJson("/api/payroll/contract-sheet/{$built['contract']->id}/approve", ['year' => $y, 'month' => $m])->assertOk();
            $this->postJson("/api/payroll/consolidated/{$y}/{$m}/approve")->assertOk();
        }

        // June was closed first, so June is what reopens — not the earlier month.
        $this->postJson('/api/payroll/consolidated/2026/6/unapprove')->assertStatus(422);
        $this->postJson('/api/payroll/consolidated/2026/5/unapprove')->assertOk();
    }
}
