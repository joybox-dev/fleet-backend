<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class MaintenanceRecord extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'vehicle_id', 'reported_by', 'approved_by',
        'garage_name', 'maintenance_type', 'maintenance_date',
        'estimated_cost', 'actual_cost', 'status', 'rejection_reason',
        'is_driver_liable', 'liable_employee_id', 'driver_deduction',
        'photo_paths', 'invoice_path', 'assignment_override_reason', 'odometer_km', 'approved_at', 'notes',
        'erp_id', 'erp_synced_at', 'erp_sync_status',

        // Accident fields
        'driver_bearing_percentage',
        'company_bearing_percentage',
        'accident_status',
        'accident_description',
    ];

    protected $casts = [
        'estimated_cost' => 'decimal:3',
        'actual_cost' => 'decimal:3',
        'driver_deduction' => 'decimal:3',
        'is_driver_liable' => 'boolean',
        'photo_paths' => 'array',
        'driver_bearing_percentage' => 'decimal:2',
        'company_bearing_percentage' => 'decimal:2',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function reportedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function liableEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'liable_employee_id')->withTrashed();
    }
}
