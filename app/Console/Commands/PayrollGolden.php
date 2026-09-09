<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Contract;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Services\ConsolidatedSheetService;
use App\Services\ContractSheetService;
use App\Services\EmployeeLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The payroll figures as a golden file: every contract sheet, every consolidated month and every
 * driver's statement the database can produce, written once and compared against on demand.
 *
 * The scenario tests pin the rules on synthetic months. This pins the REAL data: after any change
 * to the engine, a migration, or a screen's data source, `verify` says whether a single driver's
 * figure moved — before anyone opens a screen to find out. The file holds client figures, so it
 * lives outside git (storage/app is ignored).
 *
 *   php artisan payroll:golden snapshot          # freeze what the database produces today
 *   php artisan payroll:golden verify            # 0 = identical, 1 = something changed (listed)
 */
class PayrollGolden extends Command
{
    protected $signature = 'payroll:golden
        {action : snapshot | verify}
        {--file= : the golden file (default storage/app/golden/payroll.json)}
        {--company= : one company id (default: every company)}
        {--limit=200 : how many differences to list}';

    protected $description = 'Write the payroll figures of the current database to a golden file, or verify the database still produces them';

    private const TOLERANCE = 0.0005;

    public function handle(): int
    {
        $file = $this->option('file') ?: storage_path('app/golden/payroll.json');

        return match ($this->argument('action')) {
            'snapshot' => $this->snapshot($file),
            'verify' => $this->verify($file),
            default => $this->fail('action must be snapshot or verify'),
        };
    }

    private function snapshot(string $file): int
    {
        $data = $this->collect();
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $this->info("golden written: {$file}");
        $this->line('  '.$this->summary($data));

        return self::SUCCESS;
    }

    private function verify(string $file): int
    {
        if (! is_file($file)) {
            $this->error("no golden file at {$file} — run `payroll:golden snapshot` first");

            return self::FAILURE;
        }

        $golden = json_decode((string) file_get_contents($file), true);
        if (! is_array($golden) || ! isset($golden['companies'])) {
            $this->error("{$file} is not a golden file");

            return self::FAILURE;
        }

        $now = $this->collect();
        $diffs = [];
        $this->diff('', $golden['companies'], $now['companies'], $diffs);

        $this->line('golden:  '.$this->summary($golden).' (written '.($golden['generated_at'] ?? '?').' from '.($golden['database'] ?? '?').')');
        $this->line('now:     '.$this->summary($now).' ('.$now['database'].')');

        if ($diffs === []) {
            $this->info('identical — every contract sheet, consolidated month and statement produces the golden figures.');

            return self::SUCCESS;
        }

        $limit = max(1, (int) $this->option('limit'));
        $this->error(count($diffs).' difference(s):');
        foreach (array_slice($diffs, 0, $limit) as $d) {
            $this->line('  ✗ '.$d);
        }
        if (count($diffs) > $limit) {
            $this->line('  … '.(count($diffs) - $limit).' more');
        }

        return self::FAILURE;
    }

    /**
     * @return array<string, mixed>
     */
    private function collect(): array
    {
        $companies = Company::query()
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))
            ->orderBy('id')
            ->get();

        $out = [
            'generated_at' => now()->toDateTimeString(),
            'database' => DB::connection()->getDatabaseName(),
            'companies' => [],
        ];

        foreach ($companies as $company) {
            $companyId = (int) $company->id;
            app()->instance('current_company_id', $companyId);

            // Every month with a logged day, reduced in PHP so SQLite and MySQL read the same.
            $months = DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
                ->where('company_id', $companyId)
                ->distinct()
                ->pluck('log_date')
                ->map(fn ($d) => substr((string) $d, 0, 7))
                ->unique()
                ->sort()
                ->values();

            $sheets = [];
            $contracts = Contract::withoutGlobalScopes()->where('company_id', $companyId)->orderBy('id')->get();
            foreach ($contracts as $contract) {
                foreach ($months as $ym) {
                    [$y, $m] = array_map('intval', explode('-', $ym));
                    $sheet = ContractSheetService::build($contract, $y, $m, $companyId);
                    $rows = collect($sheet['drivers'] ?? [])->sortBy('employee_id')->values()->map(fn ($d) => self::sheetRow($d))->all();
                    if ($rows === [] && empty($sheet['is_approved'])) {
                        continue;
                    }
                    $sheets["{$contract->id}|{$ym}"] = [
                        'contract' => $contract->name,
                        'is_approved' => (bool) ($sheet['is_approved'] ?? false),
                        'summary' => $sheet['summary'] ?? null,
                        'approval_blockers' => $sheet['approval_blockers'] ?? [],
                        'drivers' => $rows,
                    ];
                }
            }

            $consolidated = [];
            foreach ($months as $ym) {
                [$y, $m] = array_map('intval', explode('-', $ym));
                $c = ConsolidatedSheetService::build($companyId, $y, $m);
                $consolidated[$ym] = [
                    'is_approved' => (bool) ($c['is_approved'] ?? false),
                    'summary' => $c['summary'] ?? null,
                    'standing_balances_off_sheet' => $c['standing_balances_off_sheet'] ?? [],
                    'drivers' => collect($c['drivers'] ?? [])->sortBy('employee_id')->values()->map(fn ($d) => self::consolidatedRow($d))->all(),
                ];
            }

            $employeeIds = DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
                ->where('company_id', $companyId)
                ->distinct()
                ->pluck('employee_id');
            $ledger = [];
            foreach (Employee::withoutGlobalScopes()->withTrashed()->whereIn('id', $employeeIds)->orderBy('id')->get() as $employee) {
                $h = EmployeeLedgerService::history($employee);
                $ledger[$employee->id] = [
                    'name' => $employee->name,
                    'totals' => $h['totals'] ?? null,
                    'settled_balance' => $h['settled_balance'] ?? null,
                    'settled_through' => $h['settled_through'] ?? null,
                    'movements' => count($h['movements'] ?? []),
                    'months' => collect($h['months'] ?? [])->map(fn ($mo) => [
                        'label' => $mo['label'] ?? null,
                        'status' => $mo['status'] ?? null,
                        'gross_earnings' => $mo['gross_earnings'] ?? null,
                        'manual_adjustments' => $mo['manual_adjustments'] ?? null,
                        'deductions_total' => $mo['deductions_total'] ?? null,
                        'net_payout' => $mo['net_payout'] ?? null,
                        'work_days' => $mo['work_days'] ?? null,
                        'carried_in' => $mo['carried_in'] ?? null,
                        'closing_balance' => $mo['closing_balance'] ?? null,
                        'disbursed_total' => $mo['disbursed_total'] ?? null,
                        'contracts' => collect($mo['contracts'] ?? [])->map(fn ($c) => [$c['contract_id'] ?? null, $c['gross'] ?? null, $c['net'] ?? null])->all(),
                    ])->all(),
                ];
            }

            $out['companies'][$companyId] = [
                'name' => $company->name,
                'sheets' => $sheets,
                'consolidated' => $consolidated,
                'ledger' => $ledger,
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private static function sheetRow(array $d): array
    {
        $row = [];
        foreach ([
            'employee_id', 'employee_name', 'payment_method', 'has_override', 'assignment_status', 'assigned_days',
            'actual_work_days', 'paid_days', 'payable_days', 'contract_working_days', 'orders_count', 'base_salary',
            'orders_bonus', 'deficit_deduction', 'surplus_bonus', 'absence_deduction', 'gross_contract_earnings',
            'violations_deduction', 'violations_already_deducted', 'net_payout', 'contract_default_gross',
            'override_delta', 'vehicle_type_ids', 'vehicle_type_is_mixed', 'unresolved_vehicle_type',
            'out_of_window_orders', 'unpriced_orders', 'rejected_orders_count', 'cash_collected',
        ] as $f) {
            $row[$f] = $d[$f] ?? null;
        }
        $row['manual_adjustments_total'] = $d['manual_adjustments']['total'] ?? null;
        $row['calculation_details'] = array_map(fn ($l) => [
            'label' => $l['label'] ?? '',
            'amount' => round((float) ($l['amount'] ?? 0), 3),
            'orders' => $l['orders'] ?? null,
            'is_unpriced' => (bool) ($l['is_unpriced'] ?? false),
        ], $d['calculation_details'] ?? []);

        return $row;
    }

    /**
     * @param  array<string, mixed>  $d
     * @return array<string, mixed>
     */
    private static function consolidatedRow(array $d): array
    {
        $row = [];
        foreach ([
            'employee_id', 'orders_count', 'actual_work_days', 'gross_contract_earnings', 'manual_adjustments_total',
            'pending_deductions_total', 'deductions_total', 'final_net_payout', 'deductions_applied',
            'pending_violations_deduction', 'pending_maintenance_deduction', 'pending_custody_deduction',
            'pending_driver_expenses_deduction', 'pending_advances_deduction',
            'opening_balance', 'opening_balance_from', 'amount_due', 'suggested_disbursement',
            'disbursed_bank', 'disbursed_cash', 'disbursed_total', 'remaining_balance', 'disbursement_status',
        ] as $f) {
            $row[$f] = $d[$f] ?? null;
        }
        $row['contracts_worked'] = collect($d['contracts_worked'] ?? [])->map(fn ($w) => [$w['contract_id'] ?? null, $w['gross'] ?? null, $w['net'] ?? null, $w['manual_adjustments'] ?? null])->all();
        $row['deduction_items'] = collect($d['deduction_items'] ?? [])->map(fn ($i) => [$i['source_type'] ?? null, $i['source_id'] ?? null, round((float) ($i['amount'] ?? 0), 3)])->all();

        return $row;
    }

    /**
     * Walks both trees and names every leaf that differs — numbers within a fils are equal.
     *
     * @param  array<string>  $diffs
     */
    private function diff(string $path, mixed $a, mixed $b, array &$diffs): void
    {
        if (is_array($a) && is_array($b)) {
            foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $key) {
                $p = $path === '' ? (string) $key : "{$path} › {$key}";
                if (! array_key_exists($key, $a)) {
                    $diffs[] = "{$p}: (absent in golden) → ".$this->short($b[$key]);
                } elseif (! array_key_exists($key, $b)) {
                    $diffs[] = "{$p}: ".$this->short($a[$key]).' → (absent now)';
                } else {
                    $this->diff($p, $a[$key], $b[$key], $diffs);
                }
            }

            return;
        }

        if (is_numeric($a) && is_numeric($b)) {
            if (abs((float) $a - (float) $b) >= self::TOLERANCE) {
                $diffs[] = "{$path}: {$a} → {$b}";
            }

            return;
        }

        if ($a !== $b && json_encode($a) !== json_encode($b)) {
            $diffs[] = "{$path}: ".$this->short($a).' → '.$this->short($b);
        }
    }

    private function short(mixed $v): string
    {
        $s = is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v, JSON_UNESCAPED_UNICODE);

        return mb_strlen($s) > 80 ? mb_substr($s, 0, 77).'…' : $s;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function summary(array $data): string
    {
        $sheets = 0;
        $rows = 0;
        $months = 0;
        $statements = 0;
        foreach ($data['companies'] ?? [] as $c) {
            $sheets += count($c['sheets'] ?? []);
            foreach ($c['sheets'] ?? [] as $s) {
                $rows += count($s['drivers'] ?? []);
            }
            $months += count($c['consolidated'] ?? []);
            $statements += count($c['ledger'] ?? []);
        }

        return "{$sheets} contract-months ({$rows} driver rows), {$months} consolidated months, {$statements} statements";
    }
}
