<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\EmployeeLeave;
use App\Models\Vehicle;
use App\Models\VehicleAssignment;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * The operations manager's day: who is on the road, who has no fit vehicle to be on it with,
 * whose cash is still in his pocket, who did not turn up, who is doing badly and which contracts
 * are losing money — read from the supervisors' daily log, which is the only record there is.
 * Nothing here is live: the screen says which day it describes and when the last entry was made.
 *
 * The day described is the latest day up to today with a «working» entry. The daily log opens a
 * row per driver for the whole month in advance (status «unpaid_leave», no orders) and the
 * supervisor turns rows to «working» as the day is entered — so today's rows before entry say
 * nothing, and the most recently entered day is the truth the manager acts on.
 */
class OperationsDashboardService
{
    private const PERFORMANCE_WINDOW_DAYS = 7;

    private const REPEATED_D_DAYS = 3;

    private const CASH_LATE_DAYS = 2;

    private const WORKING_DAYS_PER_MONTH = 26;

    private const WORST_LIST_SIZE = 10;

    public const GRADE_BASIS = 'تقييم اليوم: A من 100٪ فأكثر، B من 80٪، C من 60٪، D تحتها — من هدف السائق الشهري ÷ 26 يوماً إن كان في ملفه، وإلا نسبةً إلى متوسط طلبات زملائه العاملين على العقد نفسه ذلك اليوم (A من 120٪، B من 90٪، C من 60٪؛ يحتاج سائقَين عاملَين). «يحتاج تدخّلاً» = D ثلاثة أيام عمل متتالية.';

    private const DOCUMENT_ISSUES = [
        'residence_expiry' => 'إقامة منتهية',
        'driving_license_expiry' => 'رخصة قيادة منتهية',
        'work_permit_expiry' => 'إذن عمل منتهٍ',
        'health_card_expiry' => 'كرت صحي منتهٍ',
    ];

    /**
     * @param  array<int>|null  $allowedContractIds  null when the user sees every contract
     * @param  string|null  $requestedDay  a day the manager picked himself, instead of the last entered one
     * @return array<string, mixed>
     */
    public static function build(int $companyId, Carbon $today, ?array $allowedContractIds = null, ?string $requestedDay = null): array
    {
        $todayStr = $today->toDateString();
        [$day, $lastEntryAt, $entryInProgress] = self::referenceDay($companyId, $todayStr, $requestedDay);
        $dayCarbon = Carbon::parse($day)->startOfDay();

        $contracts = Contract::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->when($allowedContractIds !== null, fn ($q) => $q->whereIn('id', $allowedContractIds))
            ->orderBy('name')
            ->get(['id', 'name', 'is_active', 'required_drivers', 'required_vehicles_count']);
        $contractNames = $contracts->pluck('name', 'id');
        $contractIds = $contracts->pluck('id')->all();

        $drivers = Employee::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where('role_category', 'driver')
            ->whereIn('status', ['active', 'probation'])
            ->orderBy('name')
            ->get(['id', 'name', 'employee_number', 'target_orders_monthly', 'residence_expiry', 'driving_license_expiry', 'work_permit_expiry', 'health_card_expiry']);

        // employee => contract ids of the assignments covering the day described, within the user's
        // scope. The active flag alone is not enough: a driver moved off a contract keeps a
        // flagged-active assignment with an end date behind it, payroll stops paying him by that
        // date, and counting him as assigned turned a man nobody expected into an absentee.
        $assignments = ContractAssignment::withoutGlobalScopes()
            ->where('status', 'active')
            ->whereDate('start_date', '<=', $day)
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $day))
            ->whereIn('employee_id', $drivers->pluck('id')->all())
            ->whereIn('contract_id', $contractIds)
            ->get(['employee_id', 'contract_id'])
            ->groupBy('employee_id')
            ->map(fn (Collection $rows) => $rows->pluck('contract_id')->unique()->values()->all());

        if ($allowedContractIds !== null) {
            // A supervisor limited to some contracts sees those contracts' drivers and nobody else.
            $drivers = $drivers->filter(fn (Employee $driver) => isset($assignments[$driver->id]))->values();
        }
        $driverIds = $drivers->pluck('id')->all();
        $driversById = $drivers->keyBy('id');
        $contractsOf = fn (int $employeeId): string => collect($assignments[$employeeId] ?? [])
            ->map(fn ($id) => $contractNames[$id] ?? null)->filter()->implode('، ');

        $vehicleAssignments = VehicleAssignment::withoutGlobalScopes()
            ->where('is_active', true)
            ->whereIn('employee_id', $driverIds)
            ->get(['employee_id', 'vehicle_id'])
            ->keyBy('employee_id');
        $vehicles = Vehicle::withoutGlobalScopes()->whereNull('deleted_at')
            ->whereIn('id', $vehicleAssignments->pluck('vehicle_id')->all())
            ->get(['id', 'plate_number', 'status', 'reserved_by'])
            ->keyBy('id');

        $dayLogs = DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->whereDate('log_date', $day)
            ->whereIn('employee_id', $driverIds)
            ->get(['employee_id', 'contract_id', 'driver_status', 'orders_count'])
            ->groupBy('employee_id');

        $onLeave = EmployeeLeave::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('status', 'approved')
            ->whereIn('employee_id', $driverIds)
            ->whereDate('start_date', '<=', $day)
            ->whereDate('end_date', '>=', $day)
            ->pluck('employee_id')
            ->flip();

        // ── The roster: one line per driver, and the three counts read off it ─────────────
        $roster = [];
        $withoutVehicle = [];
        $onDuty = 0;
        $absent = 0;
        foreach ($drivers as $driver) {
            $assigned = isset($assignments[$driver->id]);
            $logs = $dayLogs->get($driver->id, collect());
            $statuses = $logs->pluck('driver_status');

            if ($statuses->contains('working')) {
                $status = 'working';
                $onDuty++;
            } elseif ($statuses->contains('paid_leave')) {
                $status = 'paid_leave';
            } elseif (isset($onLeave[$driver->id])) {
                $status = 'approved_leave';
            } elseif ($statuses->isNotEmpty()) {
                $status = 'not_working';
            } else {
                $status = 'no_log';
            }
            // Absent means expected and not there: a driver with no contract is not expected anywhere.
            $isAbsent = $assigned && in_array($status, ['not_working', 'no_log'], true);
            if ($isAbsent) {
                $absent++;
            }

            $issues = [];
            foreach (self::DOCUMENT_ISSUES as $field => $label) {
                if ($driver->$field && Carbon::parse($driver->$field)->startOfDay()->lt($today)) {
                    $issues[] = $label;
                }
            }

            $vehicleAssignment = $vehicleAssignments->get($driver->id);
            $vehicle = $vehicleAssignment ? $vehicles->get($vehicleAssignment->vehicle_id) : null;
            $vehicleProblem = null;
            if ($assigned) {
                if (! $vehicle) {
                    $vehicleProblem = 'no_vehicle';
                } elseif (in_array($vehicle->status, ['maintenance', 'reserved'], true)) {
                    $vehicleProblem = $vehicle->status;
                }
            }
            if ($vehicleProblem !== null) {
                $problemLabel = match ($vehicleProblem) {
                    'no_vehicle' => 'بلا مركبة مربوطة أصلاً',
                    'maintenance' => 'مركبته في الصيانة — بلا بديل',
                    'reserved' => 'مركبته محجوزة'.($vehicle?->reserved_by ? ' لدى '.$vehicle->reserved_by : ''),
                };
                $issues[] = $problemLabel;
                $withoutVehicle[] = [
                    'employee_id' => (int) $driver->id,
                    'name' => $driver->name,
                    'contracts' => $contractsOf((int) $driver->id),
                    'problem' => $vehicleProblem,
                    'problem_label' => $problemLabel,
                    'plate_number' => $vehicle?->plate_number,
                ];
            }

            $roster[] = [
                'employee_id' => (int) $driver->id,
                'name' => $driver->name,
                'employee_number' => $driver->employee_number,
                'contracts' => $contractsOf((int) $driver->id),
                'assigned' => $assigned,
                'status' => $status,
                'orders' => (int) $logs->sum('orders_count'),
                'is_absent' => $isAbsent,
                'issues' => $issues,
                'plate_number' => $vehicle?->plate_number,
            ];
        }
        // Whoever needs a decision comes first: the absent, then the unfit, then the rest.
        $rank = fn (array $r): int => $r['is_absent'] ? 0 : ($r['issues'] !== [] ? 1 : ($r['status'] === 'working' ? 3 : 2));
        usort($roster, fn (array $a, array $b) => [$rank($a), $a['name']] <=> [$rank($b), $b['name']]);

        // ── Cash still with drivers, and how long it has been out ───────────────────────────
        $cash = DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where('cash_pending', '>', 0)
            ->whereIn('employee_id', $driverIds)
            ->selectRaw('employee_id, SUM(cash_pending) AS total, MIN(log_date) AS oldest, COUNT(*) AS entries')
            ->groupBy('employee_id')
            ->orderByDesc('total')
            ->get()
            ->map(function ($row) use ($driversById, $assignments, $contractsOf, $today) {
                $oldest = Carbon::parse($row->oldest)->startOfDay();
                $contractIdsOf = $assignments[$row->employee_id] ?? [];

                return [
                    'employee_id' => (int) $row->employee_id,
                    'name' => $driversById[$row->employee_id]->name ?? "#{$row->employee_id}",
                    'contract_id' => $contractIdsOf[0] ?? null,
                    'contracts' => $contractsOf((int) $row->employee_id),
                    'total' => round((float) $row->total, 3),
                    'oldest_date' => $oldest->toDateString(),
                    'days_open' => (int) $oldest->diffInDays($today),
                    'entries' => (int) $row->entries,
                ];
            })
            ->values();

        $cashClose = [];
        foreach ($cash as $row) {
            $key = $row['contract_id'] ?? 0;
            $cashClose[$key] ??= [
                'contract_id' => $row['contract_id'],
                'contract_name' => $row['contract_id'] ? ($contractNames[$row['contract_id']] ?? '—') : 'بلا عقد',
                'late_drivers' => 0,
                'recent_drivers' => 0,
                'total' => 0.0,
            ];
            $cashClose[$key][$row['days_open'] >= self::CASH_LATE_DAYS ? 'late_drivers' : 'recent_drivers']++;
            $cashClose[$key]['total'] = round($cashClose[$key]['total'] + $row['total'], 3);
        }
        $cashClose = array_values($cashClose);
        usort($cashClose, fn (array $a, array $b) => [$b['late_drivers'], $b['total']] <=> [$a['late_drivers'], $a['total']]);

        // ── Contracts losing money this month — the owner's figure, from the same cache ────
        $losing = [];
        foreach (OwnerPulseService::monthRows($companyId, (int) $today->year, (int) $today->month) as $row) {
            if ($allowedContractIds !== null && ! in_array((int) $row['contract_id'], $allowedContractIds, true)) {
                continue;
            }
            if ((int) $row['orders'] <= 0 && (float) $row['driver_cost'] <= 0.0005) {
                continue;
            }
            $contribution = round((float) $row['revenue'] - (float) $row['driver_cost'], 3);
            if ($contribution >= -0.0005) {
                continue;
            }
            $losing[] = [
                'contract_id' => (int) $row['contract_id'],
                'name' => $row['contract_name'],
                'orders' => (int) $row['orders'],
                'revenue' => round((float) $row['revenue'], 3),
                'driver_cost' => round((float) $row['driver_cost'], 3),
                'contribution' => $contribution,
            ];
        }
        usort($losing, fn (array $a, array $b) => $a['contribution'] <=> $b['contribution']);

        // ── Performance: the day's grades, the worst of them, and who keeps failing ─────────
        $windowStart = $dayCarbon->copy()->subDays(self::PERFORMANCE_WINDOW_DAYS - 1)->toDateString();
        $window = DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where('driver_status', 'working')
            ->whereBetween('log_date', [$windowStart, $day])
            ->whereIn('employee_id', $driverIds)
            ->get(['employee_id', 'contract_id', 'log_date', 'orders_count']);
        $gradesByDay = self::gradeWindow($window, $driversById);

        $dayGrades = array_values(array_filter($gradesByDay[$day] ?? [], fn (array $g) => $g['grade'] !== null));
        usort($dayGrades, fn (array $a, array $b) => $a['achievement_pct'] <=> $b['achievement_pct']);
        $decorate = fn (array $g): array => $g + [
            'name' => $driversById[$g['employee_id']]->name ?? "#{$g['employee_id']}",
            'contract_name' => $contractNames[$g['contract_id']] ?? '—',
        ];
        $worst = array_map($decorate, array_slice($dayGrades, 0, self::WORST_LIST_SIZE));

        $summary = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'ungraded' => 0];
        foreach ($gradesByDay[$day] ?? [] as $g) {
            $summary[$g['grade'] ?? 'ungraded']++;
        }

        $repeated = [];
        foreach ($driverIds as $employeeId) {
            $streak = 0;
            $lastContract = null;
            for ($cursor = $dayCarbon->copy(); $cursor->toDateString() >= $windowStart; $cursor->subDay()) {
                $g = $gradesByDay[$cursor->toDateString()][$employeeId] ?? null;
                if ($g === null || $g['grade'] === null) {
                    continue;   // a day not worked neither grows the streak nor breaks it
                }
                if ($g['grade'] !== 'D') {
                    break;
                }
                $streak++;
                $lastContract ??= $g['contract_id'];
            }
            if ($streak >= self::REPEATED_D_DAYS) {
                $repeated[] = [
                    'employee_id' => (int) $employeeId,
                    'name' => $driversById[$employeeId]->name ?? "#{$employeeId}",
                    'contract_name' => $contractNames[$lastContract] ?? '—',
                    'consecutive_d_days' => $streak,
                ];
            }
        }
        usort($repeated, fn (array $a, array $b) => $b['consecutive_d_days'] <=> $a['consecutive_d_days']);

        // ── Coverage: only for contracts whose «required» was ever filled in ───────────────
        $coverage = [];
        foreach ($contracts as $contract) {
            $required = (int) ($contract->required_drivers ?: $contract->required_vehicles_count ?: 0);
            if ($required <= 0) {
                continue;
            }
            $assignedIds = collect($assignments)->filter(fn (array $ids) => in_array($contract->id, $ids, true))->keys();
            $onLeaveCount = $assignedIds->filter(fn ($id) => isset($onLeave[$id]))->count();
            $available = max(0, $assignedIds->count() - $onLeaveCount);
            $coverage[] = [
                'contract_id' => (int) $contract->id,
                'contract_name' => $contract->name,
                'required' => $required,
                'assigned' => $assignedIds->count(),
                'on_leave' => $onLeaveCount,
                'available' => $available,
                'deficit' => max(0, $required - $available),
            ];
        }

        return [
            'as_of' => [
                'day' => $day,
                'today' => $todayStr,
                'is_today' => $day === $todayStr,
                'requested' => $requestedDay !== null,
                'last_entry_at' => $lastEntryAt,
                // A later day whose entry has only begun — a handful of «working» rows — is named
                // here rather than read, so a half-entered day never shows as a day of absences.
                'entry_in_progress' => $entryInProgress,
            ],
            'kpis' => [
                'on_duty' => ['count' => $onDuty, 'of' => count($driverIds)],
                'without_vehicle' => ['count' => count($withoutVehicle)],
                'cash_with_drivers' => ['drivers' => $cash->count(), 'total' => round((float) $cash->sum('total'), 3)],
                'absent_without_leave' => ['count' => $absent],
            ],
            'cash' => $cash->all(),
            'cash_close' => $cashClose,
            'losing_contracts' => $losing,
            'performance' => [
                'day' => $day,
                'grades' => $summary,
                'worst' => $worst,
                'repeated' => $repeated,
                'basis' => self::GRADE_BASIS,
            ],
            'without_vehicle' => $withoutVehicle,
            'roster' => $roster,
            'coverage' => ['shown' => $coverage !== [], 'rows' => $coverage],
        ];
    }

    /**
     * The day the screen describes: the manager's pick, or else the latest day up to today whose
     * entry looks finished. Supervisors enter a day over hours, so the newest day with «working»
     * rows may hold one driver and sixty untouched rows — read as sixty absences. A day counts as
     * entered once its working rows reach half of the busiest day in the last two weeks; a newer
     * day below that is reported as still in progress. Also returns when the last entry was made.
     *
     * @return array{0: string, 1: ?string, 2: ?array{day: string, working: int}}
     */
    private static function referenceDay(int $companyId, string $todayStr, ?string $requestedDay): array
    {
        $entered = DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('company_id', $companyId)
            ->where('driver_status', 'working')
            ->whereDate('log_date', '<=', $todayStr);

        $lastEntry = (clone $entered)->max('updated_at');
        $lastEntry = $lastEntry ? substr((string) $lastEntry, 0, 16) : null;

        if ($requestedDay !== null) {
            return [$requestedDay, $lastEntry, null];
        }

        $counts = (clone $entered)
            ->whereDate('log_date', '>=', Carbon::parse($todayStr)->subDays(13)->toDateString())
            ->selectRaw('DATE(log_date) AS day, COUNT(*) AS working')
            ->groupBy('day')
            ->orderByDesc('day')
            ->pluck('working', 'day');

        if ($counts->isEmpty()) {
            $latest = (clone $entered)->max('log_date');

            return [$latest ? substr((string) $latest, 0, 10) : $todayStr, $lastEntry, null];
        }

        // Only the newest day can still be under entry. An older quiet day — a Friday with a third
        // of the drivers out — was quiet, not half-typed, and is read as it is.
        $days = $counts->keys()->map(fn ($day) => substr((string) $day, 0, 10))->values();
        $newest = $days->first();
        $newestWorking = (int) $counts->first();
        if ($days->count() > 1 && $newestWorking * 2 < (int) $counts->max()) {
            return [$days->get(1), $lastEntry, ['day' => $newest, 'working' => $newestWorking]];
        }

        return [$newest, $lastEntry, null];
    }

    /**
     * A grade per driver per worked day. A personal monthly target on the driver's file is spread
     * over 26 working days and measured directly; without one, the driver is read against the
     * average orders of the drivers working the same contract that day. A driver working two
     * contracts in a day is read on the one he did most of his orders on.
     *
     * @param  Collection<int, DailyLog>  $logs  «working» rows of the window
     * @param  Collection<int, Employee>  $driversById
     * @return array<string, array<int, array{employee_id: int, contract_id: ?int, orders: int, achievement_pct: ?float, grade: ?string, basis: ?string, target: ?float}>>
     */
    private static function gradeWindow(Collection $logs, Collection $driversById): array
    {
        $out = [];
        foreach ($logs->groupBy(fn ($log) => substr((string) $log->log_date, 0, 10)) as $date => $dayLogs) {
            // orders per driver per contract, and per contract the average over its drivers
            $byDriver = [];
            $byContract = [];
            foreach ($dayLogs as $log) {
                $employeeId = (int) $log->employee_id;
                $contractId = (int) $log->contract_id;
                $orders = max(0, (int) $log->orders_count);
                $byDriver[$employeeId][$contractId] = ($byDriver[$employeeId][$contractId] ?? 0) + $orders;
                $byContract[$contractId]['orders'] = ($byContract[$contractId]['orders'] ?? 0) + $orders;
                $byContract[$contractId]['drivers'][$employeeId] = true;
            }

            foreach ($byDriver as $employeeId => $perContract) {
                arsort($perContract);
                $mainContract = (int) array_key_first($perContract);
                $orders = array_sum($perContract);
                $target = (int) ($driversById[$employeeId]->target_orders_monthly ?? 0);

                $row = ['employee_id' => $employeeId, 'contract_id' => $mainContract, 'orders' => $orders, 'achievement_pct' => null, 'grade' => null, 'basis' => null, 'target' => null];
                if ($target > 0) {
                    $dailyTarget = $target / self::WORKING_DAYS_PER_MONTH;
                    $pct = round($orders / $dailyTarget * 100, 1);
                    $row = array_merge($row, ['achievement_pct' => $pct, 'grade' => self::letter($pct, [100, 80, 60]), 'basis' => 'personal_target', 'target' => round($dailyTarget, 1)]);
                } else {
                    $peers = count($byContract[$mainContract]['drivers'] ?? []);
                    if ($peers >= 2) {
                        $average = $byContract[$mainContract]['orders'] / $peers;
                        if ($average > 0) {
                            $pct = round($perContract[$mainContract] / $average * 100, 1);
                            $row = array_merge($row, ['achievement_pct' => $pct, 'grade' => self::letter($pct, [120, 90, 60]), 'basis' => 'contract_average', 'target' => round($average, 1)]);
                        }
                    }
                }
                $out[$date][$employeeId] = $row;
            }
        }

        return $out;
    }

    /**
     * @param  array{0: float, 1: float, 2: float}  $cuts  the A, B and C floors
     */
    private static function letter(float $pct, array $cuts): string
    {
        return match (true) {
            $pct >= $cuts[0] => 'A',
            $pct >= $cuts[1] => 'B',
            $pct >= $cuts[2] => 'C',
            default => 'D',
        };
    }
}
