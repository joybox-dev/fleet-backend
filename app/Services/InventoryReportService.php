<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\Employee;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use App\Models\VehicleType;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The stock-take: every administrative employee, driver, vehicle and contract the company has,
 * one row each, as things stand today — who is on which contract, who holds which vehicle, and
 * what has lapsed — with the counts a stock-take opens on.
 *
 * It is a reading of the records as they are now, not of a past date: a contract or vehicle
 * assignment carries its dates, but an employee's or a vehicle's status does not keep a history,
 * so a stock-take "as of last month" would be half true.
 *
 * A driver is on a contract when his assignment is flagged active AND its dates cover today —
 * the same window payroll pays him by. An assignment left flagged active after its end date is
 * not counted, and is named under `notes`, because that gap is what a stock-take is for.
 */
class InventoryReportService
{
    /** The documents whose lapse is reported on a driver, in the order they are listed. */
    private const DRIVER_DOCUMENTS = [
        'residence_expiry' => 'الإقامة',
        'driving_license_expiry' => 'رخصة القيادة',
        'work_permit_expiry' => 'إذن العمل',
        'health_card_expiry' => 'الكرت الصحي',
    ];

    /** An administrative employee drives nothing, so only his right to work is watched. */
    private const STAFF_DOCUMENTS = [
        'residence_expiry' => 'الإقامة',
        'work_permit_expiry' => 'إذن العمل',
    ];

    /** A vehicle document counts only when it carries a date: not every vehicle needs every one. */
    private const VEHICLE_DOCUMENTS = [
        'insurance_expiry' => 'التأمين',
        'comprehensive_insurance_expiry' => 'التأمين الشامل',
        'food_authority_license_expiry' => 'ترخيص هيئة الغذاء',
    ];

    /** The statuses of a driver who is expected to be working. */
    private const IN_SERVICE = ['active', 'probation'];

    /**
     * @param  array{staff?: bool, drivers?: bool, vehicles?: bool, contracts?: bool}  $sections  which lists the reader may see
     * @param  array<int, int>|null  $allowedDriverIds  a supervisor's drivers, or null for everyone
     * @param  array<int, int>|null  $allowedContractIds  a supervisor's contracts, or null for all
     * @return array<string, mixed>
     */
    public static function build(int $companyId, Carbon $today, array $sections = [], ?array $allowedDriverIds = null, ?array $allowedContractIds = null): array
    {
        $sections += ['staff' => true, 'drivers' => true, 'vehicles' => true, 'contracts' => true];
        $day = $today->toDateString();

        $contracts = Contract::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->when($allowedContractIds !== null, fn ($q) => $q->whereIn('id', $allowedContractIds))
            ->with('client:id,name,name_ar')
            ->orderBy('name')
            ->get();

        $employees = Employee::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->when($allowedDriverIds !== null, fn ($q) => $q->whereIn('id', $allowedDriverIds))
            ->with('adminRole:id,name')
            ->orderBy('name')
            ->get();

        [$drivers, $staff] = $employees->partition(fn (Employee $e) => $e->role_category === 'driver');

        // Flagged active, split by whether the dates still cover today.
        [$current, $lapsed] = ContractAssignment::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('status', 'active')
            ->whereIn('contract_id', $contracts->pluck('id'))
            ->whereIn('employee_id', $drivers->pluck('id'))
            ->get(['employee_id', 'contract_id', 'start_date', 'end_date'])
            ->partition(fn ($a) => substr((string) $a->start_date, 0, 10) <= $day
                && ($a->end_date === null || substr((string) $a->end_date, 0, 10) >= $day));

        $contractNames = $contracts->pluck('name', 'id');
        $contractsOfDriver = $current->groupBy('employee_id')
            ->map(fn (Collection $rows) => $rows->pluck('contract_id')->unique()->values());
        $driversOfContract = $current->groupBy('contract_id')
            ->map(fn (Collection $rows) => $rows->pluck('employee_id')->unique()->values());

        $holdings = VehicleAssignment::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->get(['vehicle_id', 'employee_id', 'contract_id']);
        $holdingOfDriver = $holdings->keyBy('employee_id');
        $holdingOfVehicle = $holdings->keyBy('vehicle_id');

        $vehicles = Vehicle::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->orderBy('plate_number')
            ->get();
        $plates = $vehicles->pluck('plate_number', 'id');
        $employeeNames = Employee::withoutGlobalScopes()->withTrashed()
            ->whereIn('id', $holdings->pluck('employee_id')->unique())
            ->pluck('name', 'id');
        $typeNames = VehicleType::withoutGlobalScopes()
            ->whereIn('id', $vehicles->pluck('vehicle_type_id')->filter()->unique())
            ->get()
            ->mapWithKeys(fn ($t) => [(int) $t->id => ($t->name_ar ?: $t->name)]);

        $contractNamesOf = fn (int $employeeId): array => ($contractsOfDriver->get($employeeId) ?? collect())
            ->map(fn ($id) => $contractNames[$id] ?? null)->filter()->values()->all();

        $staffRows = $staff->map(fn (Employee $e) => self::personRow($e, $day, self::STAFF_DOCUMENTS) + [
            'role' => $e->adminRole?->name,
        ])->values();

        $driverRows = $drivers->map(function (Employee $e) use ($day, $contractNamesOf, $holdingOfDriver, $plates) {
            $held = $holdingOfDriver->get($e->id);
            $onContracts = $contractNamesOf((int) $e->id);

            return self::personRow($e, $day, self::DRIVER_DOCUMENTS) + [
                'contracts' => $onContracts,
                'contract_names' => implode('، ', $onContracts),
                'vehicle_id' => $held?->vehicle_id,
                'vehicle_plate' => $held ? ($plates[$held->vehicle_id] ?? null) : null,
                // Out of service and still holding something of the company's.
                'holds_while_inactive' => ! in_array($e->status, [...self::IN_SERVICE, 'on_leave'], true)
                    && ($held !== null || $onContracts !== []),
            ];
        })->values();

        $vehicleRows = $vehicles->map(function (Vehicle $v) use ($day, $holdingOfVehicle, $employeeNames, $contractNames, $contractNamesOf, $typeNames) {
            $held = $holdingOfVehicle->get($v->id);
            $onContract = $held?->contract_id && isset($contractNames[$held->contract_id])
                ? [$contractNames[$held->contract_id]]
                : ($held ? $contractNamesOf((int) $held->employee_id) : []);

            return [
                'id' => (int) $v->id,
                'plate_number' => $v->plate_number,
                'make' => $v->make,
                'model' => $v->model,
                'year' => $v->year,
                'color' => $v->color,
                'vehicle_type' => $v->vehicle_type_id ? ($typeNames[$v->vehicle_type_id] ?? null) : null,
                'ownership_type' => $v->ownership_type,
                'status' => $v->status,
                'driver_id' => $held?->employee_id,
                'driver_name' => $held ? ($employeeNames[$held->employee_id] ?? null) : null,
                'contract_names' => implode('، ', $onContract),
                'odometer_km' => $v->odometer_km === null ? null : (int) $v->odometer_km,
                'insurance_expiry' => self::dateOnly($v->insurance_expiry),
                'reserved_by' => $v->status === 'reserved' ? $v->reserved_by : null,
                'reserved_until' => $v->status === 'reserved' ? self::dateOnly($v->reserved_until) : null,
                'expired_documents' => self::expired($v, $day, self::VEHICLE_DOCUMENTS),
            ];
        })->values();

        $contractRows = $contracts->map(function (Contract $c) use ($day, $driversOfContract, $holdingOfDriver) {
            $driverIds = $driversOfContract->get($c->id) ?? collect();
            $start = self::dateOnly($c->start_date);
            $end = self::dateOnly($c->end_date);

            return [
                'id' => (int) $c->id,
                'contract_number' => $c->contract_number,
                'name' => $c->name,
                'client' => $c->client?->name_ar ?: ($c->client?->name ?: $c->client_name),
                'status' => $c->status ?: ($c->is_active ? 'active' : 'inactive'),
                'start_date' => $start,
                'end_date' => $end,
                // Where today falls against the contract's own dates, whatever its status says.
                'date_state' => match (true) {
                    $end !== null && $end < $day => 'expired',
                    $start !== null && $start > $day => 'not_started',
                    default => 'running',
                },
                'client_payment_methods' => self::methodsOf($c->client_pricing_rules, $c->client_payment_method),
                'driver_payment_methods' => self::methodsOf($c->driver_pricing_rules, $c->driver_payment_method),
                'drivers_count' => $driverIds->count(),
                'vehicles_count' => $driverIds->filter(fn ($id) => $holdingOfDriver->has($id))->count(),
                'required_drivers' => $c->required_drivers,
                'required_vehicles' => $c->required_vehicles_count,
            ];
        })->values();

        $inService = $driverRows->whereIn('status', self::IN_SERVICE);

        return [
            'as_of' => $day,
            'summary' => [
                'staff' => $sections['staff'] ? [
                    'total' => $staffRows->count(),
                    'by_status' => self::tally($staffRows, 'status'),
                    'expired_documents' => $staffRows->filter(fn ($r) => $r['expired_documents'] !== [])->count(),
                ] : null,
                'drivers' => $sections['drivers'] ? [
                    'total' => $driverRows->count(),
                    'by_status' => self::tally($driverRows, 'status'),
                    'by_type' => self::tally($driverRows, 'employee_type'),
                    'in_service' => $inService->count(),
                    'on_contract' => $inService->filter(fn ($r) => $r['contracts'] !== [])->count(),
                    'without_contract' => $inService->filter(fn ($r) => $r['contracts'] === [])->count(),
                    'with_vehicle' => $inService->whereNotNull('vehicle_id')->count(),
                    'without_vehicle' => $inService->whereNull('vehicle_id')->count(),
                    'expired_documents' => $inService->filter(fn ($r) => $r['expired_documents'] !== [])->count(),
                ] : null,
                'vehicles' => $sections['vehicles'] ? [
                    'total' => $vehicleRows->count(),
                    'by_status' => self::tally($vehicleRows, 'status'),
                    'by_type' => self::tally($vehicleRows, 'vehicle_type'),
                    'by_ownership' => self::tally($vehicleRows, 'ownership_type'),
                    'with_driver' => $vehicleRows->whereNotNull('driver_id')->count(),
                    'without_driver' => $vehicleRows->whereNull('driver_id')->count(),
                    'expired_documents' => $vehicleRows->filter(fn ($r) => $r['expired_documents'] !== [])->count(),
                ] : null,
                'contracts' => $sections['contracts'] ? [
                    'total' => $contractRows->count(),
                    'by_status' => self::tally($contractRows, 'status'),
                    'running' => $contractRows->where('status', 'active')->where('date_state', 'running')->count(),
                    'expired_but_active' => $contractRows->where('status', 'active')->where('date_state', 'expired')->count(),
                    'drivers_assigned' => $current->pluck('employee_id')->unique()->count(),
                ] : null,
            ],
            // What the records disagree with themselves about — found by counting, fixed on the
            // screens that own the record.
            'notes' => [
                'lapsed_assignments' => $sections['drivers'] ? $lapsed->count() : 0,
                'lapsed_assignment_drivers' => $sections['drivers'] ? $lapsed->pluck('employee_id')->unique()->count() : 0,
                'inactive_still_holding' => $sections['drivers'] ? $driverRows->where('holds_while_inactive', true)->count() : 0,
                'contracts_expired_but_active' => $sections['contracts']
                    ? $contractRows->where('status', 'active')->where('date_state', 'expired')->pluck('name')->values()->all()
                    : [],
            ],
            'staff' => $sections['staff'] ? $staffRows->all() : null,
            'drivers' => $sections['drivers'] ? $driverRows->all() : null,
            'vehicles' => $sections['vehicles'] ? $vehicleRows->all() : null,
            'contracts' => $sections['contracts'] ? $contractRows->all() : null,
        ];
    }

    /**
     * The columns an administrative employee and a driver share.
     *
     * @param  array<string, string>  $documents
     * @return array<string, mixed>
     */
    private static function personRow(Employee $employee, string $day, array $documents): array
    {
        return [
            'id' => (int) $employee->id,
            'employee_number' => $employee->employee_number,
            'name' => $employee->name,
            'nationality' => $employee->nationality,
            'civil_id' => $employee->civil_id,
            'phone' => $employee->phone,
            'date_of_joining' => self::dateOnly($employee->date_of_joining),
            'employee_type' => $employee->employee_type,
            'status' => $employee->status,
            'expired_documents' => self::expired($employee, $day, $documents),
        ];
    }

    /**
     * The labels of the documents on a record whose date has passed. A document with no date says
     * nothing either way and is left out.
     *
     * @param  array<string, string>  $documents
     * @return array<int, string>
     */
    private static function expired(object $record, string $day, array $documents): array
    {
        $lapsed = [];
        foreach ($documents as $column => $label) {
            $date = self::dateOnly($record->{$column});
            if ($date !== null && $date < $day) {
                $lapsed[] = $label;
            }
        }

        return $lapsed;
    }

    /**
     * How a contract is priced. The method lives in the pricing rules, one per vehicle type, and a
     * contract may price its motorcycles one way and its cars another; the contract's own column
     * is only what is left on contracts that predate the rules.
     *
     * @return array<int, string>
     */
    private static function methodsOf(mixed $rules, ?string $fallback): array
    {
        $methods = collect(is_array($rules) ? $rules : [])
            ->map(fn ($rule) => is_array($rule) ? ($rule['payment_method'] ?? null) : null)
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $methods !== [] ? $methods : array_values(array_filter([$fallback]));
    }

    private static function dateOnly(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : substr((string) $value, 0, 10);
    }

    /**
     * How many rows carry each value of a column, largest first; a blank is counted as «غير محدد»
     * rather than dropped, so the parts always add up to the total.
     *
     * @return array<int, array{key: string, count: int}>
     */
    private static function tally(Collection $rows, string $column): array
    {
        return $rows->groupBy(fn ($row) => (string) ($row[$column] ?? '') === '' ? 'unspecified' : (string) $row[$column])
            ->map(fn (Collection $group, $key) => ['key' => (string) $key, 'count' => $group->count()])
            ->sortByDesc('count')
            ->values()
            ->all();
    }
}
