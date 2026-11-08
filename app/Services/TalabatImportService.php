<?php

namespace App\Services;

use App\Models\Contract;
use App\Models\ContractAssignment;
use App\Models\DailyLog;
use App\Models\Employee;
use App\Models\TalabatStatement;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Brings Talabat's monthly payment statement onto a contract, in two steps like every import here:
 * the file is read and shown (who is who, what the month comes to, what does not add up), then saved
 * as shown.
 *
 * A rider is our driver when his Talabat rider id is on that driver's assignment to the contract.
 * A rider no driver carries is listed with the drivers his name resembles; whoever imports may link
 * him to one, and the link is written on that driver's assignment so the next month finds him. A
 * rider left unlinked is kept and counted, never guessed.
 *
 * Saving a statement for a month replaces the one saved before it, and makes the contract read the
 * Talabat way from that month on (`talabat_settlement_from` moves back to it when it is earlier).
 */
class TalabatImportService
{
    private const CACHE_MINUTES = 30;

    /** @return array<string, mixed> */
    public static function preview(Contract $contract, UploadedFile $file): array
    {
        @set_time_limit(120);

        $parsed = TalabatStatementReader::read($file->getRealPath());
        // Kept as uploaded, so the month's revenue can always be traced back to Talabat's own file.
        $stored = $file->storeAs('talabat/'.$contract->id, now()->format('Ymd_His').'_'.Str::random(6).'.'.($file->getClientOriginalExtension() ?: 'csv'), 'local');

        $riderMap = self::riderMap($contract);
        $ours = self::ourOrders($contract, $parsed['year'], $parsed['month']);
        $candidates = self::candidates($contract, array_keys($ours));

        $riders = [];
        foreach ($parsed['riders'] as $rider) {
            $empId = $riderMap[$rider['rider_id']] ?? null;
            $riders[] = $rider + [
                'employee_id' => $empId,
                'employee_name' => $empId ? ($candidates[$empId] ?? self::name($empId)) : null,
                'orders_ours' => $empId ? ($ours[$empId] ?? 0) : null,
                // For a rider no driver carries: the drivers of the contract his name resembles.
                'suggestions' => $empId ? [] : self::suggest($rider['name'], $candidates, $riderMap),
            ];
        }

        $ridersTotal = round(array_sum(array_column($parsed['riders'], 'net')), 3);
        $companyTotal = round(array_sum(array_column($parsed['company_lines'], 'amount')), 3);
        $matched = array_filter(array_column($riders, 'employee_id'));
        $loggedNotOnStatement = array_values(array_diff(array_keys($ours), $matched));

        $checks = [
            [
                'ok' => abs(round($ridersTotal + $companyTotal, 3) - $parsed['net_payment']) < 0.01,
                'text' => 'صافي السائقين '.number_format($ridersTotal, 3).' + بنود الشركة '.number_format($companyTotal, 3).' = صافي الكشف '.number_format($parsed['net_payment'], 3),
            ],
            [
                'ok' => count($matched) === count($riders),
                'text' => count($matched).' من '.count($riders).' سائقاً مربوطون برقمهم في طلبات',
            ],
            [
                'ok' => $loggedNotOnStatement === [],
                'text' => $loggedNotOnStatement === []
                    ? 'كل سائق له طلبات في السجل اليومي موجود في الكشف'
                    : count($loggedNotOnStatement).' سائقاً لهم طلبات في السجل اليومي وليسوا في الكشف: '.implode('، ', array_map(fn ($id) => $candidates[$id] ?? self::name($id), $loggedNotOnStatement)),
            ],
        ];
        if ($parsed['shape'] === 'base') {
            $checks[] = ['ok' => false, 'text' => 'هذا كشف الدفع الأساسي — لا يحمل خصومات طلبات على الشركة (عجز الكاش، رسوم العقد). كشف «Total payment» أدق.'];
        }

        $existing = TalabatStatement::withoutGlobalScopes()->where('contract_id', $contract->id)
            ->where('year', $parsed['year'])->where('month', $parsed['month'])->exists();

        $token = (string) Str::uuid();
        Cache::put(self::cacheKey($token), [
            'contract_id' => $contract->id,
            'parsed' => $parsed,
            'riders' => $riders,
            'file_path' => $stored,
            'original_filename' => $file->getClientOriginalName(),
        ], now()->addMinutes(self::CACHE_MINUTES));

        return [
            'token' => $token,
            'year' => $parsed['year'],
            'month' => $parsed['month'],
            'shape' => $parsed['shape'],
            'replaces_existing' => $existing,
            'settlement_from_after' => self::settlementFromAfter($contract, $parsed['year'], $parsed['month'])->format('Y-m'),
            'totals' => [
                'riders' => count($riders),
                'deliveries' => array_sum(array_column($parsed['riders'], 'deliveries')),
                'orders_ours' => array_sum($ours),
                'pickup_payment' => round(array_sum(array_column($parsed['riders'], 'pickup_payment')), 3),
                'dropoff_payment' => round(array_sum(array_column($parsed['riders'], 'dropoff_payment')), 3),
                'service_payment' => round(array_sum(array_column($parsed['riders'], 'service_payment')), 3),
                'adjustments' => round(array_sum(array_column($parsed['riders'], 'adjustments')), 3),
                'riders_total' => $ridersTotal,
                'company_total' => $companyTotal,
                'net_payment' => $parsed['net_payment'],
            ],
            'company_lines' => $parsed['company_lines'],
            'checks' => $checks,
            'riders' => $riders,
            // Every driver a rider may be linked to: those on the contract or logged on it this month.
            'drivers' => collect($candidates)->map(fn ($name, $id) => ['id' => (int) $id, 'name' => $name])->values(),
        ];
    }

    /**
     * @param  array<string, int|null>  $links  rider id => our driver, chosen at preview for riders no driver carried
     * @return array{statement: TalabatStatement, settlement_from: string, linked: int}
     */
    public static function confirm(Contract $contract, string $token, array $links, ?User $user): array
    {
        $payload = Cache::get(self::cacheKey($token));
        if (! $payload || (int) $payload['contract_id'] !== (int) $contract->id) {
            throw ValidationException::withMessages(['token' => 'انتهت صلاحية المعاينة أو تخص عقداً آخر. ارفع الملف من جديد.']);
        }
        $parsed = $payload['parsed'];
        $allowed = array_map('intval', array_keys(self::candidates($contract, array_keys(self::ourOrders($contract, $parsed['year'], $parsed['month'])))));

        $linked = 0;
        $statement = DB::transaction(function () use ($contract, $parsed, $payload, $links, $allowed, $user, &$linked) {
            TalabatStatement::withoutGlobalScopes()->where('contract_id', $contract->id)
                ->where('year', $parsed['year'])->where('month', $parsed['month'])
                ->get()->each(function (TalabatStatement $old) {
                    $old->riders()->delete();
                    $old->delete();
                });

            $riders = $payload['riders'];
            $statement = TalabatStatement::withoutGlobalScopes()->create([
                'company_id' => $contract->company_id,
                'contract_id' => $contract->id,
                'year' => $parsed['year'],
                'month' => $parsed['month'],
                'riders_count' => count($riders),
                'deliveries' => array_sum(array_column($riders, 'deliveries')),
                'pickups' => array_sum(array_column($riders, 'pickups')),
                'dropoffs' => array_sum(array_column($riders, 'dropoffs')),
                'pickup_payment' => round(array_sum(array_column($riders, 'pickup_payment')), 3),
                'dropoff_payment' => round(array_sum(array_column($riders, 'dropoff_payment')), 3),
                'service_payment' => round(array_sum(array_column($riders, 'service_payment')), 3),
                'rider_adjustments' => round(array_sum(array_column($riders, 'adjustments')), 3),
                'riders_total' => round(array_sum(array_column($riders, 'net')), 3),
                'company_lines' => $parsed['company_lines'],
                'company_total' => round(array_sum(array_column($parsed['company_lines'], 'amount')), 3),
                'net_payment' => $parsed['net_payment'],
                'original_filename' => $payload['original_filename'],
                'file_path' => $payload['file_path'],
                'imported_by' => $user?->id,
            ]);

            foreach ($riders as $rider) {
                $empId = $rider['employee_id'];
                $chosen = isset($links[$rider['rider_id']]) ? (int) $links[$rider['rider_id']] : 0;
                if (! $empId && $chosen && in_array($chosen, $allowed, true)) {
                    $empId = $chosen;
                    $linked += self::rememberRiderId($contract, $chosen, $rider['rider_id']) ? 1 : 0;
                }
                $statement->riders()->create([
                    'rider_id' => $rider['rider_id'],
                    'name' => $rider['name'],
                    'vehicle' => $rider['vehicle'],
                    'employee_id' => $empId ?: null,
                    'hours' => $rider['hours'],
                    'deliveries' => $rider['deliveries'],
                    'pickups' => $rider['pickups'],
                    'dropoffs' => $rider['dropoffs'],
                    'pickup_payment' => $rider['pickup_payment'],
                    'dropoff_payment' => $rider['dropoff_payment'],
                    'service_payment' => $rider['service_payment'],
                    'adjustments' => $rider['adjustments'],
                    'net' => $rider['net'],
                ]);
            }

            $from = self::settlementFromAfter($contract, $parsed['year'], $parsed['month']);
            $contract->forceFill(['talabat_settlement_from' => $from->toDateString()])->saveQuietly();

            return $statement;
        });

        Cache::forget(self::cacheKey($token));
        TalabatRevenueService::forget();

        return [
            'statement' => $statement,
            'settlement_from' => Carbon::parse($contract->fresh()->talabat_settlement_from)->format('Y-m'),
            'linked' => $linked,
        ];
    }

    /** @return array<string, int> Talabat rider id => our driver, from the assignments to this contract */
    public static function riderMap(Contract $contract): array
    {
        $map = [];
        foreach (ContractAssignment::withoutGlobalScopes()->where('contract_id', $contract->id)
            ->whereNotNull('courier_id')->where('courier_id', '<>', '')
            ->orderByDesc('id')
            ->get(['courier_id', 'employee_id']) as $row) {
            $map[trim((string) $row->courier_id)] ??= (int) $row->employee_id;
        }

        return $map;
    }

    /**
     * The rider id written on the driver's latest assignment to the contract, when it carries none.
     * A driver with a different id already keeps it: the statement row is linked, his id is not moved.
     */
    private static function rememberRiderId(Contract $contract, int $employeeId, string $riderId): bool
    {
        $assignment = ContractAssignment::withoutGlobalScopes()
            ->where('contract_id', $contract->id)->where('employee_id', $employeeId)
            ->orderByDesc('start_date')->orderByDesc('id')
            ->first();
        if (! $assignment || trim((string) $assignment->courier_id) !== '') {
            return false;
        }
        $assignment->forceFill(['courier_id' => $riderId])->saveQuietly();

        return true;
    }

    /** @return array<int, int> orders per driver logged on the contract in the month */
    private static function ourOrders(Contract $contract, int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1);

        return DailyLog::withoutGlobalScopes()->whereNull('deleted_at')
            ->where('contract_id', $contract->id)
            ->whereBetween('log_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->where('orders_count', '>', 0)
            ->selectRaw('employee_id, SUM(orders_count) AS orders')
            ->groupBy('employee_id')
            ->pluck('orders', 'employee_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /**
     * The drivers a rider may be: assigned to the contract, or logged on it in the month.
     *
     * @param  list<int>  $logged
     * @return array<int, string>
     */
    private static function candidates(Contract $contract, array $logged): array
    {
        $ids = ContractAssignment::withoutGlobalScopes()->where('contract_id', $contract->id)->pluck('employee_id')
            ->merge($logged)->map(fn ($id) => (int) $id)->unique()->values()->all();

        return $ids === [] ? [] : Employee::withoutGlobalScopes()->withTrashed()->whereIn('id', $ids)
            ->orderBy('name')->pluck('name', 'id')->all();
    }

    private static function name(int $id): string
    {
        return (string) (Employee::withoutGlobalScopes()->withTrashed()->whereKey($id)->value('name') ?? "#{$id}");
    }

    /**
     * Up to three drivers whose names share the most words with the rider's — Talabat writes «Gofur
     * Hossain 1A - TC» where we write «Gafur Hussain» — skipping drivers another rider id already has.
     *
     * @param  array<int, string>  $candidates
     * @param  array<string, int>  $riderMap
     * @return list<array{id: int, name: string, score: float}>
     */
    private static function suggest(string $riderName, array $candidates, array $riderMap): array
    {
        $taken = array_flip(array_values($riderMap));
        $words = self::words($riderName);
        if ($words === []) {
            return [];
        }
        $scored = [];
        foreach ($candidates as $id => $name) {
            if (isset($taken[$id])) {
                continue;
            }
            $score = 0.0;
            foreach (self::words($name) as $w) {
                foreach ($words as $r) {
                    similar_text($w, $r, $pct);
                    if ($pct >= 75) {
                        $score += $pct / 100;
                        break;
                    }
                }
            }
            if ($score >= 0.75) {
                $scored[] = ['id' => (int) $id, 'name' => $name, 'score' => round($score, 2)];
            }
        }
        usort($scored, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($scored, 0, 3);
    }

    /** @return list<string> the name's words in lower case, without Talabat's «1A - B - TC» tags */
    private static function words(string $name): array
    {
        $name = preg_replace('/\b\d+[a-z]?\b|\b(tc|b)\b|-/i', ' ', mb_strtolower($name));

        return array_values(array_filter(preg_split('/\s+/', (string) $name), fn ($w) => mb_strlen($w) >= 3));
    }

    private static function settlementFromAfter(Contract $contract, int $year, int $month): Carbon
    {
        $candidate = Carbon::create($year, $month, 1)->startOfDay();
        $current = $contract->talabat_settlement_from ? Carbon::parse($contract->talabat_settlement_from)->startOfMonth() : null;

        return $current && $current->lt($candidate) ? $current : $candidate;
    }

    private static function cacheKey(string $token): string
    {
        return "talabat-import:{$token}";
    }
}
