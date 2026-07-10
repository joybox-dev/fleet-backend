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
        'is_active', 'is_locked', 'is_validity_enabled', 'notes',
        'required_drivers', 'daily_target', 'monthly_target',
        'target_orders_monthly', 'base_commission_rate', 'premium_commission_rate',
        'expected_monthly_revenue', 'target_driver_count',
        'expected_total_profit', 'expected_monthly_profit',
        
        // Mandatory fields (client feedback)
        'client_name', 'status', 'currency',

        // Optional default customization fields
        'default_order_commission', 'default_hourly_rate', 'default_work_hours_source',
        'default_fixed_salary', 'default_absence_divisor', 'default_monthly_target', 'default_daily_target',
        'required_drivers_count', 'required_vehicles_count',
        'expected_monthly_expenses', 'target_profit_margin',
        'default_required_valid_days',
        
        // Discrepancy thresholds
        'threshold_type', 'minor_threshold_limit', 'major_threshold_limit',

        // Pricing rules and vehicle types
        'vehicle_type_id', 'client_payment_method', 'client_pricing_rules',
        'driver_payment_method', 'driver_pricing_rules', 'capacity_target', 'capacity_pricing_rules',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_locked' => 'boolean',
        'is_validity_enabled' => 'boolean',
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
        
        // Defaults
        'default_order_commission' => 'decimal:3',
        'default_hourly_rate' => 'decimal:3',
        'default_fixed_salary' => 'decimal:3',
        'default_absence_divisor' => 'integer',
        'default_monthly_target' => 'integer',
        'default_daily_target' => 'integer',
        'required_drivers_count' => 'integer',
        'required_vehicles_count' => 'integer',
        'expected_monthly_expenses' => 'decimal:3',
        'target_profit_margin' => 'decimal:2',
        'default_required_valid_days' => 'integer',
        'minor_threshold_limit' => 'decimal:2',
        'major_threshold_limit' => 'decimal:2',

        // Pricing rules and vehicle types
        'vehicle_type_id'        => 'integer',
        'client_pricing_rules'   => 'array',
        'driver_pricing_rules'   => 'array',
        'capacity_pricing_rules' => 'array',
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

    public function vehicleType(): BelongsTo
    {
        return $this->belongsTo(VehicleType::class);
    }

    public function dailyLogs(): HasMany
    {
        return $this->hasMany(DailyLog::class);
    }

    public function vehicleAssignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ContractAssignment::class);
    }

    public function monthlyParameters(): HasMany
    {
        return $this->hasMany(ContractMonthlyParameter::class);
    }

    public function bonuses(): HasMany
    {
        return $this->hasMany(ContractBonus::class);
    }

    public function supervisorCostAllocations(): HasMany
    {
        return $this->hasMany(SupervisorCostAllocation::class);
    }

    public function getDeletionBlocks(): array
    {
        $blocks = [];

        if ($this->is_locked) {
            $blocks[] = 'لا يمكن حذف العقد لأنه مغلق ومحمي محاسبياً ضد التعديل.';
        }

        if ($this->assignments()->where('status', 'active')->exists()) {
            $blocks[] = 'لا يمكن حذف العقد لوجود تعيينات سائقين نشطة عليه حالياً.';
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
