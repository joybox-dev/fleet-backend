<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Reads the two Keeta exports the client downloads from the partner portal, in either of the
 * languages the portal writes them in:
 *
 * - the monthly statement (three sheets: partner totals, one row per rider, one row per order or
 *   adjustment), headers in Arabic («تفاصيل الشركاء»…) or English («partnerDetail»…);
 * - the «expected level» export (one sheet, one row per rider, English headers).
 *
 * Money arrives as text in several shapes — «10,086.513», «KWD 15,745.666», «‏14,757.175 د.ك.‏»,
 * «- 34.035» — and headers carry stray spaces, diacritics and dash variants, so both are normalised
 * before anything is matched. Sheets are recognised by their headers, not by their names.
 */
class KeetaWorkbookReader
{
    /** @var array<string, list<string>> */
    private const MONEY = [
        'order_pricing' => ['التسعير حسب الطلب', 'order-based pricing'],
        'experience_incentive' => ['حوافز تجربة التوصيل', 'experience incentive'],
        'capacity_incentive' => ['حوافز سعة الطلب المتاحة الصالحة (زيادة)', 'valid da capacity incentives'],
        'flexible_subsidy' => ['إعانة مرنة', 'flexible subsidy'],
        'other_rewards' => ['مكافآت أخرى', 'other rewards'],
        'extra_reward' => ['المكافأة الإضافية', 'extra reward'],
        'unlock_reward' => ['مكافأة التأهل (لكل طلب)', 'unlock reward (per order)'],
        'tips' => ['البقشيش', 'tips'],
        'deduction' => ['الخصم', 'deduction'],
        'food_compensation' => ['تعويض عن تلف الطعام', 'food compensation'],
        'other_adjustment' => ['تعديل آخر', 'other adjustment'],
        'withholding_reserve' => ['ضريبة محجوز الضمان: مخصّصة للفترة الحالية', 'withholding tax – reserve for current period'],
        'withholding_release' => ['ضريبة محجوز الضمان: تحرير', 'withholding tax – release'],
        'total_payable' => ['إجمالي المبلغ المستحق', 'total payable amount'],
    ];

    /** @var array<string, list<string>> */
    private const PARTNER = [
        'partner_id' => ['معرف الشريك', 'partner id'],
        'partner_name' => ['اسم الشريك', 'partner name'],
        'billing_cycle' => ['دورة الفوترة', 'billing cycle'],
        'invoice_amount' => ['مبلغ الفاتورة', 'invoice amount'],
    ];

    /** @var array<string, list<string>> */
    private const RIDER = [
        'billing_cycle' => ['دورة الفوترة', 'billing cycle'],
        'courier_id' => ['معرّف سائق التوصيل', 'courier id'],
        'name' => ['اسم سائق التوصيل', 'courier name'],
        'phone' => ['هاتف السائق', 'phone'],
        'is_valid' => ['صالح', 'is valid'],
        'reason' => ['السبب', 'reason'],
        'valid_days' => ['أيام الاتصال-صالحة', 'online days-valid'],
        'daily_hours' => ['ساعات الاتصال اليومي-صالحة', 'daily onlines hours-valid'],
        'peak_hours' => ['ساعات الاتصال اليومي خلال وقت الذروة-صالحة', 'daily onlines hours during peak time -valid'],
        'orders' => ['الطلبات المُسلمة', 'delivered orders'],
    ];

    /** @var array<string, list<string>> */
    private const LINE = [
        'courier_id' => ['معرّف سائق التوصيل', 'courier id'],
        'transaction_type' => ['نوع المعاملة', 'transaction type'],
        'note' => ['ملاحظة', 'note'],
        'detail' => ['المبلغ التفصيلي', 'detail amount'],
        'amount' => ['إجمالي المبلغ المستحق', 'total payable amount'],
        'ticket_id' => ['معرّف التذكرة', 'ticket id'],
        'violation_id' => ['معرّف المخالفة', 'violation id'],
        'violation_type' => ['نوع المخالفة', 'violation type'],
        'punishment' => ['طرق العقاب', 'punishment methods'],
    ];

    /** @var array<string, list<string>> */
    private const LEVEL = [
        'courier_id' => ['courier id', 'معرّف سائق التوصيل', 'معرف السائق'],
        'name' => ['name', 'الاسم', 'اسم سائق التوصيل'],
        'level' => ['current estimated level', 'المستوى المتوقع الحالي'],
        'reward' => ['current estimated reward amount', 'مبلغ المكافأة المتوقع الحالي'],
        'ontime_rate' => ['on-time rate', 'نسبة التوصيل في الوقت المحدد'],
        'completion_rate' => ['order completion % (non-delivery related)', 'نسبة إكمال الطلبات'],
        'utr' => ['utr (utilization rate)', 'utr'],
        'orders' => ['order volume', 'حجم الطلبات'],
        'acceptance_rate' => ['1-to-1 order assignment acceptance rate (%)', 'نسبة قبول الطلبات المسندة'],
    ];

    /** The transaction type of a plain delivered order; every other line is an adjustment worth keeping. */
    private const ORDER_LINE_TYPES = ['معرّف مهمة التوصيل', 'delivery task id'];

    /** @var array<string, int> */
    private const MONTHS = [
        'يناير' => 1, 'فبراير' => 2, 'مارس' => 3, 'أبريل' => 4, 'ابريل' => 4, 'مايو' => 5, 'يونيو' => 6,
        'يوليو' => 7, 'أغسطس' => 8, 'اغسطس' => 8, 'سبتمبر' => 9, 'أكتوبر' => 10, 'اكتوبر' => 10,
        'نوفمبر' => 11, 'ديسمبر' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7,
        'august' => 8, 'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
        'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9,
        'sept' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
    ];

    /**
     * The monthly statement.
     *
     * @return array{billing_cycle: string, year: int, month: int, partner: array<string, mixed>, riders: list<array<string, mixed>>, lines: list<array<string, mixed>>, order_lines: int}
     */
    public static function readStatement(string $path): array
    {
        $book = self::load($path, true);

        $partnerSheet = null;
        $riderSheet = null;
        $lineSheet = null;
        foreach ($book->getWorksheetIterator() as $sheet) {
            $header = self::headerRow($sheet);
            if (self::has($header, self::LINE, ['transaction_type', 'detail'])) {
                $lineSheet = $sheet;
            } elseif (self::has($header, self::RIDER, ['courier_id', 'orders'])) {
                $riderSheet = $sheet;
            } elseif (self::has($header, self::PARTNER, ['billing_cycle', 'invoice_amount'])) {
                $partnerSheet = $sheet;
            }
        }

        if (! $partnerSheet || ! $riderSheet) {
            throw ValidationException::withMessages([
                'file' => 'هذا ليس كشف كيتا الشهري: لم نجد ورقة ملخص الشريك وورقة السائقين. نزّل الكشف من بوابة كيتا كما هو، بأوراقه الثلاث.',
            ]);
        }

        $partnerRows = self::rows($partnerSheet, self::PARTNER + self::MONEY);
        $partnerRow = $partnerRows[0] ?? [];
        $billingCycle = trim((string) ($partnerRow['billing_cycle'] ?? ''));
        [$year, $month] = self::monthOf($billingCycle);

        $partner = [
            'partner_id' => self::text($partnerRow['partner_id'] ?? null),
            'partner_name' => self::text($partnerRow['partner_name'] ?? null),
        ] + self::money($partnerRow) + ['invoice_amount' => self::number($partnerRow['invoice_amount'] ?? null)];

        $riders = [];
        foreach (self::rows($riderSheet, self::RIDER + self::MONEY) as $row) {
            $courierId = self::identifier($row['courier_id'] ?? null);
            if ($courierId === null) {
                continue;
            }
            $riders[] = [
                'courier_id' => $courierId,
                'name' => self::text($row['name'] ?? null),
                'phone' => self::text($row['phone'] ?? null),
                'is_valid' => self::isValid($row['is_valid'] ?? null),
                'reason' => self::text($row['reason'] ?? null),
                'valid_days' => self::number($row['valid_days'] ?? null),
                'daily_hours' => self::number($row['daily_hours'] ?? null),
                'peak_hours' => self::number($row['peak_hours'] ?? null),
                'orders' => (int) round(self::number($row['orders'] ?? null)),
            ] + self::money($row);
        }

        $lines = [];
        $orderLines = 0;
        if ($lineSheet) {
            $orderTypes = array_map([self::class, 'normalize'], self::ORDER_LINE_TYPES);
            foreach (self::rows($lineSheet, self::LINE) as $row) {
                $type = self::text($row['transaction_type'] ?? null);
                if ($type !== null && in_array(self::normalize($type), $orderTypes, true)) {
                    $orderLines++;

                    continue;
                }
                $detail = self::text($row['detail'] ?? null);
                if ($detail === null && self::number($row['amount'] ?? null) == 0.0) {
                    continue;
                }
                $lines[] = [
                    'courier_id' => self::identifier($row['courier_id'] ?? null),
                    'transaction_type' => $type,
                    'label' => self::detailLabel($detail),
                    'amount' => self::number($row['amount'] ?? null),
                    'note' => self::text($row['note'] ?? null),
                    'ticket_id' => self::identifier($row['ticket_id'] ?? null),
                    'violation_id' => self::identifier($row['violation_id'] ?? null),
                    'violation_type' => self::violationType(self::text($row['violation_type'] ?? null)),
                    'punishment' => self::text($row['punishment'] ?? null),
                ];
            }
        }

        return [
            'billing_cycle' => $billingCycle,
            'year' => $year,
            'month' => $month,
            'partner' => $partner,
            'riders' => $riders,
            'lines' => $lines,
            'order_lines' => $orderLines,
        ];
    }

    /**
     * The «expected level» export.
     *
     * @return list<array{courier_id: string, name: ?string, level: ?string, reward: float, ontime_rate: ?float, completion_rate: ?float, utr: ?float, orders: int, acceptance_rate: ?float}>
     */
    public static function readLevels(string $path): array
    {
        $book = self::load($path, false);

        foreach ($book->getWorksheetIterator() as $sheet) {
            $header = self::headerRow($sheet);
            if (! self::has($header, self::LEVEL, ['courier_id', 'level'])) {
                continue;
            }

            $out = [];
            foreach (self::rows($sheet, self::LEVEL) as $row) {
                $courierId = self::identifier($row['courier_id'] ?? null);
                if ($courierId === null) {
                    continue;
                }
                $level = strtoupper(trim((string) ($row['level'] ?? '')));
                $out[] = [
                    'courier_id' => $courierId,
                    'name' => self::text($row['name'] ?? null),
                    'level' => in_array($level, ['S', 'A', 'B', 'C', 'D'], true) ? $level : null,
                    'reward' => self::number($row['reward'] ?? null),
                    'ontime_rate' => self::optionalNumber($row['ontime_rate'] ?? null),
                    'completion_rate' => self::optionalNumber($row['completion_rate'] ?? null),
                    'utr' => self::optionalNumber($row['utr'] ?? null),
                    'orders' => (int) round(self::number($row['orders'] ?? null)),
                    'acceptance_rate' => self::optionalNumber($row['acceptance_rate'] ?? null),
                ];
            }

            return $out;
        }

        throw ValidationException::withMessages([
            'file' => 'هذا ليس ملف «المستوى المتوقع» من كيتا: لم نجد عمودَي Courier ID و Current estimated level.',
        ]);
    }

    /** A header or value reduced to what matters: lower case, no diacritics, one alef, no spaces, one dash. */
    public static function normalize(string $value): string
    {
        $value = mb_strtolower($value);
        $value = preg_replace('/[\x{064B}-\x{0652}\x{0670}\x{0640}\x{200E}\x{200F}\x{00A0}]/u', '', $value);
        $value = preg_replace('/[أإآ]/u', 'ا', $value);
        $value = str_replace(['ى', 'ة'], ['ي', 'ه'], $value);
        $value = preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $value);

        return preg_replace('/\s+/u', '', $value);
    }

    /** «KWD 15,745.666», «‏14,757.175 د.ك.‏», «- 34.035», 12.5 or null → a float. */
    public static function number(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }
        if (is_int($value) || is_float($value)) {
            return (float) $value;
        }
        $text = preg_replace('/[\x{200E}\x{200F}\x{00A0}]/u', ' ', (string) $value);
        if (! preg_match('/(-)?\s*(\d[\d,]*(?:\.\d+)?)/u', $text, $m)) {
            return 0.0;
        }
        $number = (float) str_replace(',', '', $m[2]);

        return $m[1] === '-' ? -$number : $number;
    }

    /**
     * The billing cycle's year and month: «أغسطس 2026», «Jul 2026», «2026-08», «08/2026».
     *
     * @return array{0: int, 1: int}
     */
    public static function monthOf(string $billingCycle): array
    {
        $text = mb_strtolower(trim($billingCycle));
        if (preg_match('/(20\d{2})\D{1,3}(\d{1,2})(?!\d)/u', $text, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) {
            return [(int) $m[1], (int) $m[2]];
        }
        if (preg_match('/(?<!\d)(\d{1,2})\D{1,3}(20\d{2})/u', $text, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
            return [(int) $m[2], (int) $m[1]];
        }
        if (preg_match('/(20\d{2})/u', $text, $y)) {
            foreach (self::MONTHS as $name => $number) {
                if (preg_match('/(?<![\p{L}])'.preg_quote(mb_strtolower($name), '/').'(?![\p{L}])/u', $text)) {
                    return [(int) $y[1], $number];
                }
            }
        }

        throw ValidationException::withMessages([
            'file' => "لم نفهم شهر الكشف من «{$billingCycle}». يجب أن يكون في خانة «دورة الفوترة» شهر وسنة، مثل «أغسطس 2026».",
        ]);
    }

    private static function load(string $path, bool $statement): Spreadsheet
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => 'تعذّرت قراءة الملف. ارفع ملف Excel كما نزّلته من كيتا.']);
        }
        $reader->setReadDataOnly(true);
        if ($statement) {
            // The order sheet repeats the partner's id, name and group on every one of its ten
            // thousand rows; leaving those four columns out keeps the read well inside memory.
            $reader->setReadFilter(new class implements IReadFilter
            {
                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row === 1 || $row === 2 || ! in_array($columnAddress, ['A', 'B', 'C', 'D'], true);
                }
            });
        }

        return $reader->load($path);
    }

    /** @return list<string> */
    private static function headerRow(Worksheet $sheet): array
    {
        $first = $sheet->rangeToArray('A1:'.$sheet->getHighestDataColumn().'1', null, false, false, false)[0] ?? [];

        return array_map(fn ($v) => self::normalize((string) $v), $first);
    }

    /**
     * @param  list<string>  $header
     * @param  array<string, list<string>>  $aliases
     * @param  list<string>  $required
     */
    private static function has(array $header, array $aliases, array $required): bool
    {
        $map = self::columns($header, $aliases);
        foreach ($required as $field) {
            if (! isset($map[$field])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<string>  $header
     * @param  array<string, list<string>>  $aliases
     * @return array<string, int>
     */
    private static function columns(array $header, array $aliases): array
    {
        $map = [];
        foreach ($aliases as $field => $names) {
            $wanted = array_map([self::class, 'normalize'], $names);
            foreach ($header as $index => $label) {
                if ($label !== '' && in_array($label, $wanted, true)) {
                    $map[$field] = $index;
                    break;
                }
            }
        }

        return $map;
    }

    /**
     * The data rows of a sheet, keyed by field.
     *
     * @param  array<string, list<string>>  $aliases
     * @return list<array<string, mixed>>
     */
    private static function rows(Worksheet $sheet, array $aliases): array
    {
        $all = $sheet->toArray(null, false, false, false);
        $map = self::columns(array_map(fn ($v) => self::normalize((string) $v), $all[0] ?? []), $aliases);

        $out = [];
        foreach (array_slice($all, 1) as $cells) {
            $row = [];
            $empty = true;
            foreach ($map as $field => $index) {
                $value = $cells[$index] ?? null;
                $row[$field] = $value;
                if ($value !== null && $value !== '') {
                    $empty = false;
                }
            }
            if (! $empty) {
                $out[] = $row;
            }
        }

        return $out;
    }

    /** @return array<string, float> */
    private static function money(array $row): array
    {
        return [
            'order_pricing' => self::number($row['order_pricing'] ?? null),
            'experience_incentive' => self::number($row['experience_incentive'] ?? null),
            'capacity_incentive' => self::number($row['capacity_incentive'] ?? null),
            // Keeta's four other kinds of reward, paid for the month like the incentives.
            'other_income' => round(self::number($row['flexible_subsidy'] ?? null) + self::number($row['other_rewards'] ?? null)
                + self::number($row['extra_reward'] ?? null) + self::number($row['unlock_reward'] ?? null), 3),
            'tips' => self::number($row['tips'] ?? null),
            'deduction' => self::number($row['deduction'] ?? null),
            'food_compensation' => self::number($row['food_compensation'] ?? null),
            'other_adjustment' => self::number($row['other_adjustment'] ?? null),
            'withholding' => round(self::number($row['withholding_reserve'] ?? null) + self::number($row['withholding_release'] ?? null), 3),
            'total_payable' => self::number($row['total_payable'] ?? null),
        ];
    }

    private static function text(mixed $value): ?string
    {
        $text = trim(preg_replace('/\s+/u', ' ', (string) ($value ?? '')));

        return $text === '' ? null : $text;
    }

    /** Ids arrive as text or as long numbers; either way they are kept as their digits. */
    private static function identifier(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_float($value)) {
            $value = number_format($value, 0, '', '');
        }
        $text = trim((string) $value);

        return $text === '' || $text === '-' ? null : $text;
    }

    private static function isValid(mixed $value): bool
    {
        return in_array(self::normalize((string) ($value ?? '')), ['صالح', 'valid', 'yes', 'نعم'], true);
    }

    private static function optionalNumber(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : self::number($value);
    }

    /** «حوافز تجربة التوصيل:170.000» → «حوافز تجربة التوصيل»; several parts keep all their labels. */
    private static function detailLabel(?string $detail): ?string
    {
        if ($detail === null) {
            return null;
        }
        $labels = [];
        foreach (preg_split('/\n|;/u', $detail) as $part) {
            $label = trim(explode(':', $part, 2)[0]);
            if ($label !== '') {
                $labels[] = $label;
            }
        }

        return $labels === [] ? $detail : implode(' + ', array_unique($labels));
    }

    /** «معرّف المخالفة:123 نوع المخالفة:أصناف خاطئة» → «أصناف خاطئة». */
    private static function violationType(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (preg_match('/(?:نوع المخالفة|violation type)\s*:\s*(.+)$/iu', $value, $m)) {
            return trim($m[1]);
        }

        return $value;
    }
}
