<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LeaveType extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'name', 'name_ar', 'is_paid', 'max_days_per_year',
        'requires_approval', 'penalty_multiplier', 'is_active',
    ];

    protected $casts = [
        'is_paid' => 'boolean',
        'requires_approval' => 'boolean',
        'is_active' => 'boolean',
        'penalty_multiplier' => 'decimal:1',
    ];

    /**
     * The leave types a company starts with. A company created after the first install had none,
     * and there is no screen to add them, so not one leave could be recorded. Absence is not among
     * them: the system has no absence days.
     *
     * @var array<int, array<string, mixed>>
     */
    public const DEFAULTS = [
        ['name' => 'Annual Leave', 'name_ar' => 'إجازة سنوية', 'is_paid' => true, 'max_days_per_year' => 30],
        ['name' => 'Sick Leave', 'name_ar' => 'إجازة مرضية', 'is_paid' => true, 'max_days_per_year' => 15],
        ['name' => 'Unpaid Leave', 'name_ar' => 'إجازة بدون راتب', 'is_paid' => false, 'max_days_per_year' => null],
        ['name' => 'Emergency Leave', 'name_ar' => 'إجازة طارئة', 'is_paid' => true, 'max_days_per_year' => 3],
    ];

    /** Give a company with no leave types the defaults; a company that has any keeps its own. */
    public static function ensureDefaults(int $companyId): void
    {
        if (self::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            return;
        }

        foreach (self::DEFAULTS as $type) {
            self::withoutGlobalScopes()->forceCreate($type + [
                'company_id' => $companyId,
                'requires_approval' => true,
                'penalty_multiplier' => 1.0,
                'is_active' => true,
            ]);
        }
    }

    public function leaves(): HasMany
    {
        return $this->hasMany(EmployeeLeave::class);
    }

    public function getDeletionBlocks(): array
    {
        $blocks = [];

        if ($this->leaves()->exists()) {
            $blocks[] = 'لا يمكن حذف نوع الإجازة لوجود طلبات إجازة مسجلة به تحت الموظفين.';
        }

        return $blocks;
    }
}
