<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One decision about one charge in one payroll month: defer it to a named later month, or (for
 * an advance) collect a different instalment this month. The month it belongs to is the month
 * whose approval would otherwise have taken the charge.
 */
class PayrollDeductionOverride extends Model
{
    use BelongsToCompany;

    public const ACTION_DEFER = 'defer';

    public const ACTION_AMOUNT = 'amount';

    protected $fillable = [
        'company_id',
        'year',
        'month',
        'source_type',
        'source_id',
        'action',
        'amount',
        'defer_to_year',
        'defer_to_month',
        'reason',
        'created_by',
    ];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'source_id' => 'integer',
        'amount' => 'decimal:3',
        'defer_to_year' => 'integer',
        'defer_to_month' => 'integer',
    ];

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Months since year 0 — the arithmetic the deferral window is decided on. */
    public static function index(int $year, int $month): int
    {
        return $year * 12 + $month;
    }

    public function monthIndex(): int
    {
        return self::index((int) $this->year, (int) $this->month);
    }

    public function deferIndex(): ?int
    {
        return $this->defer_to_year && $this->defer_to_month
            ? self::index((int) $this->defer_to_year, (int) $this->defer_to_month)
            : null;
    }

    public function monthLabel(): string
    {
        return sprintf('%02d/%d', $this->month, $this->year);
    }

    public function deferToLabel(): ?string
    {
        return $this->defer_to_month ? sprintf('%02d/%d', $this->defer_to_month, $this->defer_to_year) : null;
    }

    /** @return array<string, mixed> */
    public function toRow(): array
    {
        return [
            'id' => $this->id,
            'year' => (int) $this->year,
            'month' => (int) $this->month,
            'source_type' => $this->source_type,
            'source_id' => (int) $this->source_id,
            'action' => $this->action,
            'amount' => $this->amount === null ? null : round((float) $this->amount, 3),
            'defer_to' => $this->deferToLabel(),
            'defer_to_year' => $this->defer_to_year,
            'defer_to_month' => $this->defer_to_month,
            'reason' => $this->reason,
            'created_by_name' => $this->relationLoaded('createdBy') ? $this->createdBy?->name : null,
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }

    /**
     * What the decisions say about one charge in one month.
     *
     * A charge deferred OUT of a month, or still travelling towards a later one, is not charged
     * here. A charge whose deferral has LANDED — this month or an earlier one — is charged here
     * even though its own date lies elsewhere, and keeps being offered month after month until a
     * sheet actually collects it: a fine sent to September must not vanish because the driver had
     * no September row. Deferral chains (08 → 09 → 10) work because each month carries its own
     * row; the latest landing names where the charge came from.
     *
     * The driver's statement asks a narrower question — which single month a charge belongs to —
     * and passes $exactTarget so a landed charge appears in its target month only.
     *
     * @param  Collection<int, self>  $rows  every override of this charge, any month
     * @return array{include: bool, own: ?self, deferred_from: ?string, amount: ?float}
     */
    public static function decide($rows, int $year, int $month, bool $exactTarget = false): array
    {
        $here = self::index($year, $month);
        $none = ['include' => true, 'own' => null, 'deferred_from' => null, 'amount' => null];
        if ($rows === null || $rows->isEmpty()) {
            return $none;
        }

        $own = $rows->first(fn (self $o) => (int) $o->year === $year && (int) $o->month === $month);
        if ($own && $own->action === self::ACTION_DEFER) {
            return ['include' => false, 'own' => $own, 'deferred_from' => null, 'amount' => null];
        }
        if ($own && $own->action === self::ACTION_AMOUNT) {
            return ['include' => true, 'own' => $own, 'deferred_from' => null, 'amount' => round((float) $own->amount, 3)];
        }

        $travelling = $rows->first(fn (self $o) => $o->action === self::ACTION_DEFER
            && $o->monthIndex() <= $here && ($o->deferIndex() ?? 0) > $here);
        if ($travelling) {
            return ['include' => false, 'own' => $travelling, 'deferred_from' => null, 'amount' => null];
        }

        $landed = $rows
            ->filter(fn (self $o) => $o->action === self::ACTION_DEFER && $o->deferIndex() !== null
                && ($exactTarget ? $o->deferIndex() === $here : $o->deferIndex() <= $here))
            ->sortByDesc(fn (self $o) => $o->deferIndex())
            ->first();
        if ($landed) {
            return ['include' => true, 'own' => null, 'deferred_from' => $landed->monthLabel(), 'amount' => null];
        }

        return $none;
    }

    /** The table a source type lives in. */
    public static function sourceModel(string $type): ?string
    {
        return match ($type) {
            'violation' => Violation::class,
            'maintenance' => MaintenanceRecord::class,
            'custody' => CustodyItem::class,
            'driver_expense' => DriverExpense::class,
            'advance' => SalaryAdvance::class,
            default => null,
        };
    }

    /** The column naming the employee a source charges. */
    public static function employeeColumn(string $type): string
    {
        return $type === 'maintenance' ? 'liable_employee_id' : 'employee_id';
    }
}
