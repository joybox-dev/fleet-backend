<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\ContractPayrollAdjustment;
use App\Models\CustodyItem;
use App\Models\CustodyType;
use App\Models\DailyLog;
use App\Models\DriverContractOverride;
use App\Models\DriverExpense;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\LeaveType;
use App\Models\MaintenanceRecord;
use App\Models\SalaryAdvance;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\Violation;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * The same scenario FiveContractPaymentMethodsScenarioTest proves in PHP, laid into a real
 * database so the screens can be opened against it.
 *
 * The point is the comparison: the payroll engine is tested, but the contract dashboard computes
 * a driver's salary a second time in the browser, from its own copy of the rules. Only opening the
 * screens against known-correct figures shows whether the two agree.
 *
 * Five contracts, one per driver payment method, each pricing two vehicle types and carrying the
 * same twenty drivers. Every working driver holds one of each deduction type.
 *
 *   php artisan db:seed --class=ScenarioMatrixSeeder
 *
 * NEVER run this against production data — it creates its own company and writes nothing outside it.
 */
class ScenarioMatrixSeeder extends Seeder
{
    private const YEAR = 2026;

    private const MONTH = 5;

    /**
     * The second month, copied from the first — July, not June, because it has 31 days as May
     * does. The driver who works every day of the month then maps day for day, and comparing the
     * two months carries no calendar noise of its own.
     */
    private const MIRROR_MONTH = 7;

    /** The one driver whose second month is deliberately not a copy. */
    private const BROKEN_DRIVER = '١ ثابت على مركبة واحدة';

    /** Large enough to outrun both his month's pay and the balance he carried into it. */
    private const BREAKING_FINE = 450.000;

    private const TYPE_BIKE = 1;

    private const TYPE_SMALL = 2;

    private const TYPE_LARGE = 3;

    private Company $company;

    private User $user;

    private Client $client;

    /** @var array<int, Vehicle> */
    private array $vehicles = [];

    public function run(): void
    {
        $this->company = Company::firstOrCreate(
            ['code' => 'matrix'],
            [
                'name' => 'Scenario Matrix Co',
                'name_ar' => 'شركة سيناريو الفحص',
                'is_active' => true,
                'currency' => 'KWD',
                'enabled_modules' => Company::DEFAULT_MODULES,
            ]
        );

        app()->instance('current_company_id', $this->company->id);

        $this->user = User::firstOrCreate(
            ['email' => 'matrix@fleet.test'],
            [
                'name' => 'مشرف السيناريو',
                'password' => bcrypt('matrix1234'),
                'role' => 'admin',
                'company_id' => $this->company->id,
            ]
        );

        $this->client = Client::firstOrCreate(
            ['name' => 'عميل السيناريو', 'company_id' => $this->company->id]
        );

        foreach ([
            self::TYPE_BIKE => ['Motorcycle', 'سيكل'],
            self::TYPE_SMALL => ['Small Car', 'سيارة صغيرة'],
            self::TYPE_LARGE => ['Large Car', 'سيارة كبيرة'],
        ] as $id => [$name, $nameAr]) {
            DB::table('vehicle_types')->updateOrInsert(['id' => $id], [
                'company_id' => $this->company->id,
                'name' => $name,
                'name_ar' => $nameAr,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($this->contractMatrix() as $key => $spec) {
            foreach ($spec['types'] as $type) {
                $plate = 'س-'.strtoupper(substr($key, 0, 3)).'-'.$type;
                $this->vehicles[$key.':'.$type] = Vehicle::firstOrCreate(
                    ['plate_number' => $plate, 'company_id' => $this->company->id],
                    ['make' => 'Fleet', 'status' => 'working', 'vehicle_type_id' => $type]
                );
            }
        }

        // Not required by anything the scenario proves — the custody screens simply have nothing to
        // offer without them, and an empty dropdown is a poor thing to hand somebody browsing.
        foreach ([['هاتف', '📱'], ['شريحة', '💳'], ['زي', '👕'], ['كاش', '💵'], ['أخرى', '📦']] as [$name, $icon]) {
            CustodyType::firstOrCreate(
                ['company_id' => $this->company->id, 'name' => $name],
                ['icon' => $icon]
            );
        }

        foreach ($this->contractMatrix() as $key => $spec) {
            $this->buildContract($key, $spec);
        }

        // Only possible once every contract exists, so it is a second pass.
        $this->linkSecondContracts();

        // Only possible once the first month is complete, since it is copied from the database.
        $this->mirrorMonth();

        $this->command?->info('  الشركة: '.$this->company->name.' (code: matrix)');
        $this->command?->info('  الدخول: matrix@fleet.test / matrix1234');
        $this->command?->info('  الشهر: '.self::MONTH.'/'.self::YEAR.' ومرآته '.self::MIRROR_MONTH.'/'.self::YEAR);
        $this->command?->info('  '.Contract::where('company_id', $this->company->id)->count().' عقود، '
            .Employee::where('company_id', $this->company->id)->count().' سائق، '
            .DailyLog::where('company_id', $this->company->id)->count().' سجل يومي');
    }

    /** @return array<string, array{client: string, driver: string, types: array{int, int}}> */
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
     * What the contract was SIGNED expecting, as opposed to what the month actually did. The
     * dashboard compares the two and reports the variance, and with these left empty it was
     * comparing every real figure against zero.
     *
     * The plan is built from the contract's own cost base: expected expenses are the month's driver
     * cost rounded up, and expected revenue is that cost at a 15% margin — the figure the contract
     * would have to bill to be worth signing. May then misses it, which is the point: the screen is
     * meant to show a plan and a result that differ.
     *
     * Twenty drivers, ten orders a day each, twenty-six working days — so 260 orders a month per
     * driver and 5,200 across the contract.
     *
     * @return array<string, mixed>
     */
    private function contractPlan(string $key): array
    {
        [$revenue, $expenses] = match ($key) {
            'fixed' => [5500, 4700],
            'tiers' => [5000, 4300],
            'hybrid' => [4800, 4100],
            'zones' => [4100, 3500],
            'zones_tiers' => [4400, 3750],
        };

        return [
            'expected_monthly_revenue' => $revenue,
            'expected_monthly_expenses' => $expenses,
            'expected_monthly_profit' => $revenue - $expenses,
            'expected_total_profit' => $revenue - $expenses,
            'target_profit_margin' => 15,

            'default_daily_target' => 10,
            'default_monthly_target' => 260,
            'daily_target' => 10,
            'monthly_target' => 260,
            'capacity_target' => 5200,

            // Two priced vehicle types plus the one a driver strays onto.
            'required_vehicles_count' => 3,
            'required_drivers' => 20,
            'target_driver_count' => 20,

            // Of the 26 working days, two may fall short without breaching the agreement.
            'default_required_valid_days' => 24,
        ];
    }

    /**
     * What the CLIENT is billed — the same rules the scenario test uses, so the screens show the
     * same revenue the test asserts. Each contract prices only the two vehicle types it runs; the
     * third a driver strays onto has no rule, which is how an unbillable order is meant to read.
     *
     * @return array<string, array<string, mixed>>
     */
    private function clientRules(string $key, int $typeA, int $typeB): array
    {
        return match ($key) {
            'fixed' => [
                (string) $typeA => ['payment_method' => 'fixed', 'fixed_amount' => 900],
                (string) $typeB => ['payment_method' => 'fixed', 'fixed_amount' => 600],
            ],
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
            'hybrid' => [
                (string) $typeA => ['payment_method' => 'hybrid', 'fixed_amount' => 750],
                (string) $typeB => ['payment_method' => 'hybrid', 'fixed_amount' => 450],
            ],
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
            // The south zone is deliberately left unpriced here: a zone the drivers worked and the
            // client agreement never covered.
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

    /** The second vehicle type is priced at 80% of the first, so a split month reads differently. */
    private function driverRule(string $method, bool $second): array
    {
        $f = $second ? 0.8 : 1.0;

        return match ($method) {
            'fixed' => ['payment_method' => 'fixed', 'fixed_amount' => round(260 * $f, 3), 'fixed_target' => 0],
            'tiers' => ['payment_method' => 'tiers', 'tiers' => [
                ['min' => 1, 'max' => 100, 'price' => round(0.500 * $f, 3)],
                ['min' => 101, 'max' => null, 'price' => round(0.900 * $f, 3)],
            ]],
            'hybrid' => ['payment_method' => 'hybrid', 'hybrid_fixed' => round(120 * $f, 3), 'hybrid_tiers' => [
                ['min' => 1, 'max' => null, 'price' => round(0.300 * $f, 3)],
            ]],
            'zones' => ['payment_method' => 'zones', 'zones' => [
                ['id' => 'Z1', 'name' => 'شمال', 'price' => round(0.400 * $f, 3)],
                ['id' => 'Z2', 'name' => 'جنوب', 'price' => round(0.600 * $f, 3)],
            ]],
            'zones_tiers' => ['payment_method' => 'zones_tiers', 'zones_tiers' => [
                ['id' => 'Z1', 'name' => 'شمال', 'tiers' => [
                    ['min' => 1, 'max' => 100, 'price' => round(0.450 * $f, 3)],
                    ['min' => 101, 'max' => null, 'price' => round(0.700 * $f, 3)],
                ]],
                ['id' => 'Z2', 'name' => 'جنوب', 'tiers' => [
                    ['min' => 1, 'max' => 100, 'price' => round(0.650 * $f, 3)],
                    ['min' => 101, 'max' => null, 'price' => round(0.900 * $f, 3)],
                ]],
            ]],
        };
    }

    /**
     * The «works on two contracts» driver of each contract, kept aside so the second half of his
     * month can be added once every contract exists.
     *
     * @var array<string, Employee>
     */
    private array $multiContractDrivers = [];

    /**
     * Each of those drivers picks up a SECOND contract — ten more days on the next contract in the
     * matrix — plus one fine raised there.
     *
     * The fine is the point: it is raised once, and it must be taken from him once, however many
     * contracts he worked. Without this pass the driver carries the name of the case and none of
     * its substance, which is how it went unnoticed until the scenario was read on screen.
     */
    private function linkSecondContracts(): void
    {
        $keys = array_keys($this->contractMatrix());

        foreach ($keys as $i => $key) {
            $driver = $this->multiContractDrivers[$key] ?? null;
            if (! $driver) {
                continue;
            }

            $otherKey = $keys[($i + 1) % count($keys)];
            $other = Contract::where('company_id', $this->company->id)
                ->where('contract_number', 'MX-'.strtoupper($otherKey))
                ->first();

            if (! $other || ContractAssignment::where('employee_id', $driver->id)->where('contract_id', $other->id)->exists()) {
                continue;
            }

            ContractAssignment::create([
                'employee_id' => $driver->id,
                'contract_id' => $other->id,
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-31',
                'status' => 'active',
                'company_id' => $this->company->id,
            ]);

            $otherTypeA = $this->contractMatrix()[$otherKey]['types'][0];
            $otherVehicle = $this->vehicles[$otherKey.':'.$otherTypeA];
            $this->workDays($driver, $other, $otherVehicle, range(15, 24));

            // Charged to the contract it was raised on, the way the violations screen attributes it.
            $this->fine($driver, $otherVehicle, 30.000, 30.000, 'وقوف ممنوع — على العقد الثاني', $other->id);
        }
    }

    /**
     * A second month whose inputs are a copy of the first.
     *
     * This is the screens checked against themselves. Two months carrying identical data must
     * produce identical sheets, so any figure that differs between them is a fault in how a month
     * is scoped rather than in the arithmetic — a charge counted in the month after the one it
     * arose in, an instalment taken twice, a window that quietly widened. Reading one month alone
     * cannot show any of that; reading two identical ones shows all of it at a glance.
     *
     * Assignments and overrides are COPIED into their own second-month window rather than having
     * the first month's window stretched over both. A driver hired mid-month must be hired
     * mid-month again, not retroactively present from the first — and an override that priced
     * half of May must price half of July, not all of it.
     *
     * Two things are deliberately not copied:
     *
     *  - The salary advance. Its instalment already recurs on its own, one per month, off the same
     *    record. A second advance would charge the second month twice over.
     *  - One driver, who is handed a fine the first month never had. Without him the running
     *    balance has nothing to carry, and the case the owner actually asked to see — a driver
     *    whose deductions outrun his pay, who ends the month owing the company rather than being
     *    owed — never appears on the screen at all.
     */
    private function mirrorMonth(): void
    {
        $companyId = $this->company->id;
        $mirrorStart = sprintf('%04d-%02d-01', self::YEAR, self::MIRROR_MONTH);
        $mirrorEnd = Carbon::create(self::YEAR, self::MIRROR_MONTH, 1)->endOfMonth()->toDateString();

        if (DailyLog::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereDate('log_date', '>=', $mirrorStart)->exists()) {
            $this->command?->info('  الشهر الثاني موجود مسبقاً — لم يُعد بناؤه');

            return;
        }

        $copied = DB::transaction(fn () => $this->copyMonthInto($mirrorStart, $mirrorEnd));

        foreach ($copied as $table => $count) {
            $this->command?->info('    '.str_pad($table, 30).$count);
        }
    }

    /**
     * The copy itself, in one transaction: the month is mirrored whole or not at all.
     *
     * Half a mirrored month would be worse than none. The comparison this exists for reads any
     * difference between the two months as a fault in the code, so a copy that stopped partway
     * would present itself as a pile of bugs that are nothing but the interrupted copy.
     *
     * @return array<string, int>
     */
    private function copyMonthInto(string $mirrorStart, string $mirrorEnd): array
    {
        $companyId = $this->company->id;
        $step = self::MIRROR_MONTH - self::MONTH;
        $shift = fn ($date) => $date
            ? Carbon::parse(substr((string) $date, 0, 10))->addMonthsNoOverflow($step)->toDateString()
            : null;

        // Nothing in the second month is billable while the contract is closed before it starts.
        Contract::withoutGlobalScopes()->where('company_id', $companyId)
            ->whereNull('deleted_at')
            ->whereDate('end_date', '<', $mirrorEnd)
            ->update(['end_date' => $mirrorEnd]);

        // Each assignment gets its own copy, so the two months never both claim the same driver.
        $assignmentMap = [];
        foreach (DB::table('contract_assignments')->where('company_id', $companyId)->orderBy('id')->get() as $row) {
            $new = (array) $row;
            unset($new['id']);
            $new['start_date'] = $shift($row->start_date);
            $new['end_date'] = $shift($row->end_date) ?? $mirrorEnd;
            $new['created_at'] = now();
            $new['updated_at'] = now();
            $assignmentMap[$row->id] = DB::table('contract_assignments')->insertGetId($new);
        }

        foreach (DB::table('driver_contract_overrides')->where('company_id', $companyId)->orderBy('id')->get() as $row) {
            if (! isset($assignmentMap[$row->contract_assignment_id])) {
                continue;
            }
            $new = (array) $row;
            unset($new['id']);
            $new['contract_assignment_id'] = $assignmentMap[$row->contract_assignment_id];
            $new['effective_from'] = $shift($row->effective_from);
            $new['effective_to'] = $shift($row->effective_to);
            $new['created_at'] = now();
            $new['updated_at'] = now();
            DB::table('driver_contract_overrides')->insert($new);
        }

        // Everything else is the same row on a later date. Copied at table level rather than
        // through the models: a copy must reproduce what is there, not re-run creation rules that
        // have moved on since the first month was written.
        $copy = function (string $table, array $dateColumns) use ($companyId, $shift) {
            $columns = collect(DB::select("SHOW COLUMNS FROM `{$table}`"))->pluck('Field')->all();
            $rows = DB::table($table)->where('company_id', $companyId)
                ->when(in_array('deleted_at', $columns, true), fn ($q) => $q->whereNull('deleted_at'))
                ->orderBy('id')->get();

            $batch = [];
            foreach ($rows as $row) {
                $new = (array) $row;
                unset($new['id']);
                foreach ($dateColumns as $column) {
                    if (! empty($new[$column])) {
                        $new[$column] = $shift($new[$column]);
                    }
                }
                // The copy is a new record and has never been anywhere. Carrying the original's
                // external keys over would hand two rows the same identity outside this system.
                // Only the identifiers are cleared — erp_sync_status is a state, not a key, and
                // the column refuses null.
                foreach (['erp_id', 'erp_synced_at', 'serial_number'] as $external) {
                    if (array_key_exists($external, $new)) {
                        $new[$external] = null;
                    }
                }
                $new['created_at'] = now();
                $new['updated_at'] = now();
                $batch[] = $new;
            }

            foreach (array_chunk($batch, 200) as $chunk) {
                DB::table($table)->insert($chunk);
            }

            return count($batch);
        };

        $copied = [
            'daily_logs' => $copy('daily_logs', ['log_date']),
            'violations' => $copy('violations', ['violation_date']),
            'maintenance_records' => $copy('maintenance_records', ['maintenance_date']),
            'custody_items' => $copy('custody_items', ['issued_date', 'returned_date']),
            'driver_expenses' => $copy('driver_expenses', ['expense_date']),
            'employee_leaves' => $copy('employee_leaves', ['start_date', 'end_date']),
        ];

        // This one is keyed by month rather than dated, so it is shifted by its own columns.
        $adjustments = DB::table('contract_payroll_adjustments')
            ->where('company_id', $companyId)->orderBy('id')->get();
        foreach ($adjustments as $row) {
            $new = (array) $row;
            unset($new['id']);
            $new['year'] = self::YEAR;
            $new['month'] = self::MIRROR_MONTH;
            $new['created_at'] = now();
            $new['updated_at'] = now();
            DB::table('contract_payroll_adjustments')->insert($new);
        }
        $copied['contract_payroll_adjustments'] = $adjustments->count();

        $this->breakOneDriver($mirrorStart);

        return $copied;
    }

    /**
     * The single difference between the two months.
     *
     * A fine big enough to take his month past zero AND past what he carried in from the first
     * month, so the balance does not merely shrink — it turns. That turn is the whole point: the
     * financial-account screen is meant to say which month a driver stopped being owed money and
     * started owing it, and it can only be trusted once there is a driver it has to say it about.
     */
    private function breakOneDriver(string $mirrorStart): void
    {
        $driver = Employee::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('name', self::BROKEN_DRIVER.' — '.$this->methodLabel('fixed'))
            ->first();

        $contract = Contract::withoutGlobalScopes()
            ->where('company_id', $this->company->id)
            ->where('contract_number', 'MX-FIXED')
            ->first();

        if (! $driver || ! $contract) {
            return;
        }

        Violation::create([
            'company_id' => $this->company->id,
            'employee_id' => $driver->id,
            'vehicle_id' => $this->vehicles['fixed:'.self::TYPE_BIKE]->id,
            'created_by' => $this->user->id,
            'violation_date' => Carbon::parse($mirrorStart)->addDays(7)->toDateString(),
            'violation_type' => 'حادث جسيم — الشهر الثاني وحده',
            'amount' => self::BREAKING_FINE,
            'driver_deduction' => self::BREAKING_FINE,
            'driver_share' => self::BREAKING_FINE,
            'contract_share' => 0.000,
            'charge_contract_id' => $contract->id,
            'is_driver_liable' => true,
            'is_deducted' => false,
        ]);

        $this->command?->info('    السائق المكسور: '.$driver->name.' (#'.$driver->id.')');
    }

    private function buildContract(string $key, array $spec): void
    {
        [$typeA, $typeB] = $spec['types'];
        $vA = $this->vehicles[$key.':'.$typeA];
        $vB = $this->vehicles[$key.':'.$typeB];

        $contract = Contract::firstOrCreate(
            ['contract_number' => 'MX-'.strtoupper($key), 'company_id' => $this->company->id],
            [
                'client_id' => $this->client->id,
                'name' => 'عقد '.$this->methodLabel($spec['driver']),
                'payment_type' => 'per_order',
                'start_date' => '2026-05-01',
                'end_date' => '2026-05-31',
                'client_payment_method' => $spec['client'],
                'driver_payment_method' => $spec['driver'],
                'currency' => 'KWD',
                'default_required_work_days' => 26,
                'default_absence_divisor' => 26,
                ...$this->contractPlan($key),
                'is_validity_enabled' => false,
                'client_pricing_rules' => $this->clientRules($key, $typeA, $typeB),
                'driver_pricing_rules' => [
                    (string) $typeA => $this->driverRule($spec['driver'], false),
                    (string) $typeB => $this->driverRule($spec['driver'], true),
                ],
            ]
        );

        if ($contract->assignments()->exists()) {
            return; // already seeded
        }

        $unpricedType = collect([self::TYPE_BIKE, self::TYPE_SMALL, self::TYPE_LARGE])->diff($spec['types'])->first();
        $unpricedVehicle = Vehicle::firstOrCreate(
            ['plate_number' => 'س-غير-'.$unpricedType, 'company_id' => $this->company->id],
            ['make' => 'Fleet', 'status' => 'working', 'vehicle_type_id' => $unpricedType]
        );

        $hire = function (string $label, string $from = '2026-05-01', ?string $to = '2026-05-31') use ($contract, $key) {
            $driver = Employee::create([
                'name' => $label.' — '.$this->methodLabel($key),
                'employee_number' => 'MX-'.strtoupper(substr(md5($label.$key), 0, 6)),
                'company_id' => $this->company->id,
                'status' => 'active',
                'role_category' => 'driver',
                'date_of_joining' => '2026-01-01',
                'actual_salary' => 0.000,
                'official_salary' => 120.000,
            ]);

            $assignment = ContractAssignment::create([
                'employee_id' => $driver->id,
                'contract_id' => $contract->id,
                'start_date' => $from,
                'end_date' => $to,
                'status' => 'active',
                'company_id' => $this->company->id,
            ]);

            return [$driver, $assignment];
        };

        $workers = [];

        [$workers['ثابت']] = $hire('١ ثابت على مركبة واحدة');
        $this->workDays($workers['ثابت'], $contract, $vA, range(1, 26));

        [$workers['نوعين']] = $hire('٢ ثابت على مركبتين');
        $this->workDays($workers['نوعين'], $contract, $vA, range(1, 13));
        $this->workDays($workers['نوعين'], $contract, $vB, range(14, 26));

        [$workers['جزئي']] = $hire('٣ تعيين جزئي', '2026-05-16');
        $this->workDays($workers['جزئي'], $contract, $vA, range(16, 23));

        [$workers['كامل الشهر']] = $hire('٤ داوم الشهر كامل');
        $this->workDays($workers['كامل الشهر'], $contract, $vA, range(1, 31));

        [$workers['نوع غير مسعّر']] = $hire('٥ نوع مركبة بلا تسعير');
        $this->workDays($workers['نوع غير مسعّر'], $contract, $vA, range(1, 10));
        $this->workDays($workers['نوع غير مسعّر'], $contract, $unpricedVehicle, range(12, 16));

        [$workers['بلا عمل']] = $hire('٦ بلا أي عمل');

        [$workers['استثناء جزئي'], $assignment] = $hire('٧ استثناء نصف الشهر');
        $this->workDays($workers['استثناء جزئي'], $contract, $vA, range(1, 26));
        DriverContractOverride::create([
            'contract_assignment_id' => $assignment->id,
            'company_id' => $this->company->id,
            'override_type' => 'fixed',
            'custom_pricing_rules' => ['fixed_amount' => 240, 'fixed_target' => 0],
            'customization_reason' => 'اتفاق للنصف الثاني من الشهر',
            'effective_from' => '2026-05-14',
            'effective_to' => '2026-05-31',
        ]);

        [$workers['مدين']] = $hire('٨ خصومه أكبر من راتبه');
        $this->workDays($workers['مدين'], $contract, $vA, range(1, 26));
        $this->fine($workers['مدين'], $vA, 400.000, 400.000, 'غرامة كبيرة');

        // Thirteen days here; linkSecondContracts() gives him his other contract once all five exist.
        [$workers['عقدين']] = $hire('٩ يعمل على عقدين');
        $this->workDays($workers['عقدين'], $contract, $vA, range(1, 13));
        $this->multiContractDrivers[$key] = $workers['عقدين'];

        [$workers['بلا فئة']] = $hire('١٠ طلبات بلا فئة');
        foreach (range(1, 10) as $day) {
            $this->log($workers['بلا فئة'], $contract, $vA, $day, 10, ['Z1' => 6]);
        }

        [$workers['مستلم']] = $hire('١١ استلم المركبة يوم ١٥');
        $this->workDays($workers['مستلم'], $contract, $vA, range(1, 10));
        VehicleAssignment::create([
            'company_id' => $this->company->id, 'vehicle_id' => $vA->id,
            'employee_id' => $workers['ثابت']->id, 'contract_id' => $contract->id,
            'assigned_date' => '2026-05-01', 'unassigned_date' => '2026-05-15', 'is_active' => false,
        ]);
        VehicleAssignment::create([
            'company_id' => $this->company->id, 'vehicle_id' => $vA->id,
            'employee_id' => $workers['مستلم']->id, 'contract_id' => $contract->id,
            'assigned_date' => '2026-05-15', 'is_active' => true,
        ]);

        [$workers['كل الخصوم']] = $hire('١٢ كل أنواع الخصم');
        $this->workDays($workers['كل الخصوم'], $contract, $vA, range(1, 26));

        [$workers['عاجز عن الهدف'], $assignment] = $hire('١٣ لم يبلغ الهدف');
        $this->workDays($workers['عاجز عن الهدف'], $contract, $vA, range(1, 26));
        DriverContractOverride::create([
            'contract_assignment_id' => $assignment->id, 'company_id' => $this->company->id,
            'override_type' => 'fixed',
            'custom_pricing_rules' => ['fixed_amount' => 260, 'fixed_target' => 300, 'fixed_deficit_rate' => 0.100],
            'customization_reason' => 'راتب بهدف شهري',
            'effective_from' => '2026-05-01', 'effective_to' => '2026-05-31',
        ]);

        [$workers['متجاوز الهدف'], $assignment] = $hire('١٤ تجاوز الهدف');
        $this->workDays($workers['متجاوز الهدف'], $contract, $vA, range(1, 26));
        DriverContractOverride::create([
            'contract_assignment_id' => $assignment->id, 'company_id' => $this->company->id,
            'override_type' => 'fixed',
            'custom_pricing_rules' => ['fixed_amount' => 260, 'fixed_target' => 200, 'fixed_deficit_rate' => 0.100],
            'customization_reason' => 'راتب بهدف شهري منخفض',
            'effective_from' => '2026-05-01', 'effective_to' => '2026-05-31',
        ]);

        [$workers['حصص مقسومة']] = $hire('١٥ خصوم مقسومة مع الشركة');
        $this->workDays($workers['حصص مقسومة'], $contract, $vA, range(1, 10));
        $this->fine($workers['حصص مقسومة'], $vA, 100.000, 40.000, 'مخالفة مقسومة', $contract->id);
        DriverExpense::create([
            'employee_id' => $workers['حصص مقسومة']->id, 'vehicle_id' => $vA->id,
            'expense_type' => 'tyres', 'amount' => 30.000, 'driver_amount' => 12.000,
            'company_amount' => 18.000, 'borne_by' => 'split', 'expense_date' => '2026-05-09',
            'is_deducted' => false, 'company_id' => $this->company->id,
        ]);
        MaintenanceRecord::create([
            'vehicle_id' => $vA->id, 'maintenance_type' => 'accident', 'maintenance_date' => '2026-05-09',
            'status' => 'approved', 'actual_cost' => 200.000, 'is_driver_liable' => true,
            'liable_employee_id' => $workers['حصص مقسومة']->id,
            'driver_bearing_percentage' => 40, 'company_bearing_percentage' => 60,
            'driver_deduction' => 80.000, 'reported_by' => $this->user->id,
            'company_id' => $this->company->id,
        ]);

        [$workers['على الشركة']] = $hire('١٦ كل خصومه على الشركة');
        $this->workDays($workers['على الشركة'], $contract, $vA, range(1, 10));
        $this->fine($workers['على الشركة'], $vA, 100.000, 0.000, 'مخالفة على الشركة', $contract->id, false);
        DriverExpense::create([
            'employee_id' => $workers['على الشركة']->id, 'vehicle_id' => $vA->id,
            'expense_type' => 'oil', 'amount' => 30.000, 'driver_amount' => 0.000,
            'company_amount' => 30.000, 'borne_by' => 'company', 'expense_date' => '2026-05-09',
            'is_deducted' => false, 'company_id' => $this->company->id,
        ]);

        // Every working driver carries the full deduction set, so each situation above is visible
        // against real money on the screens as well as in the payroll engine.
        foreach ($workers as $driver) {
            $this->attachEveryDeduction($driver, $contract, $vA);
        }

        // 17-20. A driver paid by each of the OTHER four methods, through an override — which is
        // what makes every contract exercise all five. They work a full month like anybody else.
        // A zone-based override on a client not billed by zone is refused by the screen, so those
        // drivers are seeded with no override and stay on the contract's own pricing.
        $clientIsZoned = $spec['client'] === 'zones';

        foreach (array_diff(['fixed', 'tiers', 'hybrid', 'zones', 'zones_tiers'], [$spec['driver']]) as $method) {
            [$driver, $assignment] = $hire('طريقة '.$this->methodLabel($method));
            $this->workDays($driver, $contract, $vA, range(1, 26));
            VehicleAssignment::create([
                'company_id' => $this->company->id, 'vehicle_id' => $vA->id,
                'employee_id' => $driver->id, 'contract_id' => $contract->id,
                'assigned_date' => '2026-05-01', 'is_active' => true,
            ]);

            $zoneBased = in_array($method, ['zones', 'zones_tiers'], true);
            if (! $zoneBased || $clientIsZoned) {
                DriverContractOverride::create(array_merge([
                    'contract_assignment_id' => $assignment->id,
                    'company_id' => $this->company->id,
                    'override_type' => $method,
                    'customization_reason' => 'اتفاق خاص — '.$this->methodLabel($method),
                    'effective_from' => '2026-05-01',
                    'effective_to' => '2026-05-31',
                ], $this->overrideTerms($method)));
            }

            $this->attachEveryDeduction($driver, $contract, $vA);
        }
    }

    /** The same override terms the scenario test uses, so both produce the same salaries. */
    private function overrideTerms(string $method): array
    {
        return match ($method) {
            // Every term lives in custom_pricing_rules — that is where the override screen writes
            // them and where the model's accessors read them back.
            'fixed' => ['custom_pricing_rules' => ['fixed_amount' => 240, 'fixed_target' => 0]],
            'tiers' => ['custom_pricing_rules' => ['tiers' => [['min' => 1, 'max' => null, 'price' => 0.550]]]],
            'hybrid' => ['custom_pricing_rules' => [
                'hybrid_fixed' => 130,
                'hybrid_tiers' => [['min' => 1, 'max' => null, 'price' => 0.350]],
            ]],
            'zones' => ['custom_pricing_rules' => ['zones' => [
                ['id' => 'Z1', 'name' => 'شمال', 'price' => 0.420],
            ]]],
            'zones_tiers' => ['custom_pricing_rules' => ['zones_tiers' => [
                ['id' => 'Z1', 'name' => 'شمال', 'tiers' => [['min' => 1, 'max' => null, 'price' => 0.470]]],
            ]]],
        };
    }

    private function methodLabel(string $method): string
    {
        return match ($method) {
            'fixed' => 'راتب ثابت',
            'tiers' => 'شرائح',
            'hybrid' => 'هجين',
            'zones' => 'فئات',
            'zones_tiers' => 'شرائح فئات',
            default => $method,
        };
    }

    private function workDays(Employee $driver, Contract $contract, Vehicle $vehicle, array $days): void
    {
        foreach ($days as $day) {
            $this->log($driver, $contract, $vehicle, $day, 10, ['Z1' => 5, 'Z2' => 5]);
        }
    }

    private function log(Employee $driver, Contract $contract, Vehicle $vehicle, int $day, int $orders, array $zones): void
    {
        DailyLog::create([
            'employee_id' => $driver->id,
            'contract_id' => $contract->id,
            'vehicle_id' => $vehicle->id,
            'log_date' => sprintf('%04d-%02d-%02d', self::YEAR, self::MONTH, $day),
            'driver_status' => 'working',
            'orders_count' => $orders,
            'notes' => json_encode(['zone_orders' => $zones]),
            'company_id' => $this->company->id,
            'created_by' => $this->user->id,
        ]);
    }

    private function fine(Employee $driver, Vehicle $vehicle, float $amount, float $driverShare, string $type, ?int $chargeContract = null, bool $driverLiable = true): void
    {
        Violation::create([
            'company_id' => $this->company->id,
            'employee_id' => $driver->id,
            'vehicle_id' => $vehicle->id,
            'created_by' => $this->user->id,
            'violation_date' => '2026-05-08',
            'violation_type' => $type,
            'amount' => $amount,
            'driver_deduction' => $driverShare,
            'driver_share' => $driverShare,
            'contract_share' => round($amount - $driverShare, 3),
            'charge_contract_id' => $chargeContract,
            'is_driver_liable' => $driverLiable,
            'is_deducted' => false,
        ]);
    }

    /** The baseline every working driver carries: 85.000 of company charges plus a 5.000 adjustment. */
    private function attachEveryDeduction(Employee $driver, Contract $contract, Vehicle $vehicle): void
    {
        $this->fine($driver, $vehicle, 20.000, 20.000, 'تجاوز السرعة', $contract->id);

        MaintenanceRecord::create([
            'vehicle_id' => $vehicle->id, 'maintenance_type' => 'repair', 'maintenance_date' => '2026-05-11',
            'status' => 'approved', 'is_driver_liable' => true, 'liable_employee_id' => $driver->id,
            'driver_deduction' => 15.000, 'reported_by' => $this->user->id, 'company_id' => $this->company->id,
        ]);

        CustodyItem::create([
            'employee_id' => $driver->id, 'item_description' => 'جهاز تتبع',
            'custody_type_id' => CustodyType::where('name', 'هاتف')->value('id'),
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

        // Kept so the leave screens have something real in them. It is NOT a deduction: a driver is
        // paid for the days he worked, so these two days already cost him their pay.
        $unpaid = LeaveType::firstOrCreate(
            ['company_id' => $this->company->id, 'name' => 'Unpaid Leave'],
            ['name_ar' => 'إجازة بدون راتب', 'is_paid' => false]
        );
        EmployeeLeave::create([
            'employee_id' => $driver->id, 'leave_type_id' => $unpaid->id,
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
}
