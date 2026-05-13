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
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_locked' => 'boolean',
        'rate_per_order' => 'decimal:3',
        'fixed_monthly'  => 'decimal:3',
        'required_drivers' => 'integer',
        'daily_target'     => 'integer',
        'monthly_target'   => 'integer',
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
}
