<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceRecord extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'vehicle_id', 'reported_by', 'approved_by',
        'garage_name', 'maintenance_type', 'maintenance_date',
        'estimated_cost', 'actual_cost', 'status', 'rejection_reason',
        'is_driver_liable', 'liable_employee_id', 'driver_deduction',
        'photo_paths', 'invoice_path', 'odometer_km', 'approved_at', 'notes',
        'erp_id', 'erp_synced_at', 'erp_sync_status',
    ];

    protected $casts = [
        'estimated_cost'   => 'decimal:3',
        'actual_cost'      => 'decimal:3',
        'driver_deduction' => 'decimal:3',
        'is_driver_liable' => 'boolean',
        'photo_paths'      => 'array',
    ];

    public function vehicle(): BelongsTo         { return $this->belongsTo(Vehicle::class); }
    public function reportedBy(): BelongsTo      { return $this->belongsTo(User::class, 'reported_by'); }
    public function approvedBy(): BelongsTo      { return $this->belongsTo(User::class, 'approved_by'); }
    public function liableEmployee(): BelongsTo  { return $this->belongsTo(Employee::class, 'liable_employee_id'); }
}
