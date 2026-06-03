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
        'is_paid'            => 'boolean',
        'requires_approval'  => 'boolean',
        'is_active'          => 'boolean',
        'penalty_multiplier' => 'decimal:1',
    ];

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
