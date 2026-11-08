<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Reads the payment export Talabat sends its 3PL partners, as CSV or Excel, in either of the shapes
 * it comes in:
 *
 * - the month's total payment: one row per rider (pickup and drop-off payments, the service-level
 *   payment, deductions and adjustments, «Net Cost», «Net Payment») and a row with no rider that
 *   carries what Talabat charges the company itself — the cash deficit, contract fees;
 * - the base payment: one row per rider, half-month and batch, which is added up per rider.
 *
 * Columns are found by their headers, normalised (case, spaces, punctuation), so a renamed or moved
 * column still reads; a rider's net is his payments plus whatever else his row carries.
 */
class TalabatStatementReader
{
    /** @var array<string, list<string>> field => normalised header names, first match wins */
    private const COLUMNS = [
        'month' => ['month'],
        'year' => ['year'],
        'rider_id' => ['riderid'],
        'name' => ['ridername'],
        'vehicle' => ['vehicle'],
        'hours' => ['evaluatedhours', 'hours'],
        'deliveries' => ['totalcompleteddeliveries', 'orders'],
        'pickups' => ['pickupscount', 'netpickups', 'pickup'],
        'dropoffs' => ['dropoffscount', 'netdropoffs'],
        'pickup_payment' => ['pickuppayment'],
        'dropoff_payment' => ['dropoffpayment'],
        'service_payment' => ['servicelevelbasedachievementtotalpayment', 'batchwise'],
        'net' => ['netcost', 'totalbasepaymet', 'totalbasepayment'],
        'net_payment' => ['netpayment'],
        // The base payment's deductions, which its «Total Base Paymet» leaves out.
        'deduction' => ['deduction', 'operatorloginusetotherideroperatorappdeductions'],
    ];

    /** Columns that are not money: never a company line. */
    private const NOT_MONEY = ['month', 'year', 'riderid', 'ridername', '3pllegalname', 'vehicle', 'period', 'batchno',
        'evaluatedhours', 'hours', 'totalcompleteddeliveries', 'orders', 'pickupscount', 'pickupcancellations',
        'dropoffscount', 'dropoffcancellations', 'netpickups', 'pickup', 'netdropoffs', 'pickuprate', 'dropoffrate',
        'netcost', 'netpayment', 'totalbasepaymet', 'totalbasepayment', 'deduction'];

    /**
     * @return array{year: int, month: int, shape: string, riders: list<array<string, mixed>>, company_lines: list<array{label: string, amount: float}>, net_payment: float}
     */
    public static function read(string $path): array
    {
        $rows = self::rows($path);
        $header = null;
        $headerIndex = 0;
        foreach ($rows as $i => $row) {
            $normalised = array_map([self::class, 'normalise'], $row);
            if (in_array('riderid', $normalised, true) && in_array('pickuppayment', $normalised, true)) {
                $header = $normalised;
                $headerIndex = $i;
                $labels = $row;
                break;
            }
        }
        if ($header === null) {
            throw ValidationException::withMessages(['file' => 'هذا ليس كشف مدفوعات طلبات: لم يُعثر على عمودَي «Rider ID» و«Pickup Payment».']);
        }

        $col = [];
        foreach (self::COLUMNS as $field => $names) {
            foreach ($names as $name) {
                $at = array_search($name, $header, true);
                if ($at !== false) {
                    $col[$field] = $at;
                    break;
                }
            }
        }
        foreach (['month', 'year', 'rider_id', 'pickup_payment', 'dropoff_payment', 'net'] as $required) {
            if (! isset($col[$required])) {
                throw ValidationException::withMessages(['file' => "عمود ناقص في كشف طلبات: {$required}."]);
            }
        }
        $shape = isset($col['net_payment']) ? 'total' : 'base';

        $riders = [];
        $company = [];
        $months = [];
        $netPayment = 0.0;
        foreach (array_slice($rows, $headerIndex + 1) as $row) {
            if (count(array_filter($row, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $get = fn (string $field) => isset($col[$field]) ? ($row[$col[$field]] ?? '') : '';
            $money = fn (string $field) => self::number($get($field));
            if (trim((string) $get('month')) === '' && trim((string) $get('year')) === '') {
                continue;
            }
            $months[sprintf('%04d-%02d', (int) $get('year'), (int) $get('month'))] = true;
            // A rider's net: «Net Cost» already takes his deductions off; the base payment's total does not.
            $riderNet = $shape === 'total' ? $money('net') : round($money('net') + $money('deduction'), 3);
            $rowNet = $shape === 'total' ? $money('net_payment') : $riderNet;
            $netPayment += $rowNet;

            $riderId = trim((string) $get('rider_id'));
            if ($riderId === '') {
                // The company's own row: every money column that is not zero is a line of its own.
                foreach ($header as $i => $name) {
                    if (in_array($name, self::NOT_MONEY, true) || $name === '') {
                        continue;
                    }
                    $amount = self::number($row[$i] ?? '');
                    if (abs($amount) >= 0.0005) {
                        $label = trim((string) ($labels[$i] ?? $name));
                        $company[$label] = round(($company[$label] ?? 0) + $amount, 3);
                    }
                }

                continue;
            }

            $riders[$riderId] ??= [
                'rider_id' => $riderId,
                'name' => trim(preg_replace('/\s+/', ' ', (string) $get('name'))),
                'vehicle' => self::vehicle((string) $get('vehicle')),
                'hours' => 0.0, 'deliveries' => 0, 'pickups' => 0, 'dropoffs' => 0,
                'pickup_payment' => 0.0, 'dropoff_payment' => 0.0, 'service_payment' => 0.0, 'net' => 0.0,
            ];
            $r = &$riders[$riderId];
            $r['hours'] = round($r['hours'] + $money('hours'), 2);
            $r['deliveries'] += (int) round($money('deliveries'));
            $r['pickups'] += (int) round($money('pickups'));
            $r['dropoffs'] += (int) round($money('dropoffs'));
            $r['pickup_payment'] = round($r['pickup_payment'] + $money('pickup_payment'), 3);
            $r['dropoff_payment'] = round($r['dropoff_payment'] + $money('dropoff_payment'), 3);
            $r['service_payment'] = round($r['service_payment'] + $money('service_payment'), 3);
            $r['net'] = round($r['net'] + $riderNet, 3);
            unset($r);
        }

        if (count($months) !== 1) {
            throw ValidationException::withMessages(['file' => count($months) === 0
                ? 'الكشف فارغ: لا سطر فيه شهر وسنة.'
                : 'الكشف فيه أكثر من شهر ('.implode('، ', array_keys($months)).') — ارفع كشف شهر واحد.']);
        }
        [$year, $month] = array_map('intval', explode('-', array_key_first($months)));

        $riders = array_values(array_map(function (array $r) {
            $r['adjustments'] = round($r['net'] - $r['pickup_payment'] - $r['dropoff_payment'] - $r['service_payment'], 3);

            return $r;
        }, $riders));

        return [
            'year' => $year,
            'month' => $month,
            'shape' => $shape,
            'riders' => $riders,
            'company_lines' => array_values(array_map(fn ($label, $amount) => ['label' => $label, 'amount' => $amount], array_keys($company), $company)),
            'net_payment' => round($netPayment, 3),
        ];
    }

    /** @return list<list<mixed>> */
    private static function rows(string $path): array
    {
        try {
            $book = IOFactory::load($path);
        } catch (\Throwable $e) {
            report($e);
            throw ValidationException::withMessages(['file' => 'تعذّرت قراءة الملف — ارفع كشف طلبات كما نزل (CSV أو Excel).']);
        }

        return $book->getActiveSheet()->toArray(null, true, false, false);
    }

    private static function normalise(mixed $value): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim((string) $value)));
    }

    private static function number(mixed $value): float
    {
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $text = str_replace([',', 'KWD', 'kwd', ' '], '', trim((string) $value));
        if (preg_match('/^\((.*)\)$/', $text, $m)) {
            $text = '-'.$m[1];
        }

        return is_numeric($text) ? (float) $text : 0.0;
    }

    private static function vehicle(string $value): string
    {
        $v = mb_strtolower($value);

        return str_contains($v, 'bike') || str_contains($v, 'motor') || str_contains($v, 'دراج') ? 'bike' : 'car';
    }
}
