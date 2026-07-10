<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DriverContractOverride extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'contract_assignment_id',
        'custom_order_commission',
        'custom_hourly_rate',
        'custom_fixed_salary',
        'custom_monthly_target',
        'custom_monthly_bonus',
        'custom_valid_days',
        'customization_reason',
        'effective_from',
        'effective_to',
        'override_type',
        'custom_pricing_rules',
    ];

    protected $casts = [
        'custom_order_commission' => 'float',
        'custom_hourly_rate' => 'float',
        'custom_fixed_salary' => 'float',
        'custom_monthly_target' => 'integer',
        'custom_monthly_bonus' => 'float',
        'custom_valid_days' => 'integer',
        'effective_from' => 'date',
        'effective_to' => 'date',
        'custom_pricing_rules' => 'array',
    ];

    protected $appends = [
        'fixed_amount',
        'fixed_target',
        'fixed_deficit_rate',
        'fixed_bonus_type',
        'fixed_surplus_bonus',
        'fixed_surplus_rate',
        'zone_target_orders',
        'zone_deficit_rate',
        'zone_bonus_type',
        'zone_target_bonus',
        'zone_surplus_rate',
        'zones',
        'tiers',
        'hybrid_fixed',
        'hybrid_tiers',
        'zones_tiers',
    ];

    public function getFixedAmountAttribute()
    {
        return $this->custom_pricing_rules['fixed_amount'] ?? null;
    }

    public function getFixedTargetAttribute()
    {
        return $this->custom_pricing_rules['fixed_target'] ?? null;
    }

    public function getFixedDeficitRateAttribute()
    {
        return $this->custom_pricing_rules['fixed_deficit_rate'] ?? null;
    }

    public function getFixedBonusTypeAttribute()
    {
        return $this->custom_pricing_rules['fixed_bonus_type'] ?? 'lump_sum';
    }

    public function getFixedSurplusBonusAttribute()
    {
        return $this->custom_pricing_rules['fixed_surplus_bonus'] ?? null;
    }

    public function getFixedSurplusRateAttribute()
    {
        return $this->custom_pricing_rules['fixed_surplus_rate'] ?? null;
    }

    public function getZoneTargetOrdersAttribute()
    {
        return $this->custom_pricing_rules['zone_target_orders'] ?? null;
    }

    public function getZoneDeficitRateAttribute()
    {
        return $this->custom_pricing_rules['zone_deficit_rate'] ?? null;
    }

    public function getZoneBonusTypeAttribute()
    {
        return $this->custom_pricing_rules['zone_bonus_type'] ?? 'lump_sum';
    }

    public function getZoneTargetBonusAttribute()
    {
        return $this->custom_pricing_rules['zone_target_bonus'] ?? null;
    }

    public function getZoneSurplusRateAttribute()
    {
        return $this->custom_pricing_rules['zone_surplus_rate'] ?? null;
    }

    public function getZonesAttribute()
    {
        return $this->custom_pricing_rules['zones'] ?? [];
    }

    public function getTiersAttribute()
    {
        return $this->custom_pricing_rules['tiers'] ?? [];
    }

    public function getHybridFixedAttribute()
    {
        return $this->custom_pricing_rules['hybrid_fixed'] ?? null;
    }

    public function getHybridTiersAttribute()
    {
        return $this->custom_pricing_rules['hybrid_tiers'] ?? [];
    }

    public function getZonesTiersAttribute()
    {
        return $this->custom_pricing_rules['zones_tiers'] ?? [];
    }

    public function contractAssignment(): BelongsTo
    {
        return $this->belongsTo(ContractAssignment::class);
    }
}
