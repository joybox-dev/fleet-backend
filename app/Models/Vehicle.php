<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Vehicle extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'plate_number', 'make', 'model', 'year', 'color', 'vin', 'status',
        'odometer_km', 'last_oil_change_km', 'oil_change_interval_km',
        'monthly_fuel_allowance',
        'insurance_expiry', 'comprehensive_insurance_expiry',
        'food_authority_license_expiry', 'next_service_due',
        'notes', 'erp_id', 'erp_synced_at', 'erp_sync_status',
    ];

    protected $casts = [
        'monthly_fuel_allowance' => 'decimal:3',
    ];

    public function vehicleAssignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class);
    }

    public function activeAssignment(): HasOne
    {
        return $this->hasOne(VehicleAssignment::class)->where('is_active', true);
    }

    public function dailyLogs(): HasMany
    {
        return $this->hasMany(DailyLog::class);
    }

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }

    public function violations(): HasMany
    {
        return $this->hasMany(Violation::class);
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(VehicleExpense::class);
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
