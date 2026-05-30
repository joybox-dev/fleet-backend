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
    ];

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
