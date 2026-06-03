<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contract extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'client_id', 'contract_number', 'name', 'payment_type',
        'rate_per_order', 'fixed_monthly', 'start_date', 'end_date',
        'is_active', 'is_locked', 'notes',
        'required_drivers', 'daily_target', 'monthly_target',
        'target_orders_monthly', 'base_commission_rate', 'premium_commission_rate',
        'expected_monthly_revenue', 'target_driver_count',
        'expected_total_profit', 'expected_monthly_profit',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_locked' => 'boolean',
        'rate_per_order' => 'decimal:3',
        'fixed_monthly'  => 'decimal:3',
        'required_drivers' => 'integer',
        'daily_target'     => 'integer',
        'monthly_target'   => 'integer',
        'target_orders_monthly' => 'integer',
        'base_commission_rate' => 'decimal:3',
        'premium_commission_rate' => 'decimal:3',
        'expected_monthly_revenue' => 'decimal:3',
        'target_driver_count' => 'integer',
        'expected_total_profit' => 'decimal:3',
        'expected_monthly_profit' => 'decimal:3',
    ];

    protected static function booted(): void
    {
        static::saving(function ($contract) {
            if ($contract->expected_total_profit) {
                $startDate = \Carbon\Carbon::parse($contract->start_date);
                $endDate = $contract->end_date ? \Carbon\Carbon::parse($contract->end_date) : null;
                if ($endDate) {
                    $months = max(1, $startDate->diffInMonths($endDate->copy()->addDay()));
                    $contract->expected_monthly_profit = $contract->expected_total_profit / $months;
                } else {
                    $contract->expected_monthly_profit = $contract->expected_total_profit;
                }
            } else {
                $contract->expected_monthly_profit = null;
            }
        });
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function dailyLogs(): HasMany
    {
        return $this->hasMany(DailyLog::class);
    }

    public function vehicleAssignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class);
    }

    public function getDeletionBlocks(): array
    {
        $blocks = [];

        if ($this->is_locked) {
            $blocks[] = 'لا يمكن حذف العقد لأنه مغلق ومحمي محاسبياً ضد التعديل.';
        }

        if ($this->vehicleAssignments()->where('is_active', true)->exists()) {
            $blocks[] = 'لا يمكن حذف العقد لوجود سيارات نشطة معينة عليه حالياً.';
        }

        if ($this->dailyLogs()->exists()) {
            $blocks[] = 'لا يمكن حذف العقد لوجود سجلات تشغيل يومية مسجلة عليه.';
        }

        return $blocks;
    }

    /**
     * Retrieve the model for a bound value, including soft-deleted ones.
     */
    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)
            ->withTrashed()
            ->first();
    }
}
