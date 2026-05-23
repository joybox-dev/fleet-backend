<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Violation extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'employee_id', 'vehicle_id', 'created_by', 'violation_date',
        'violation_type', 'reference_number', 'amount',
        'photo_path', 'is_driver_liable', 'is_deducted', 'payroll_slip_id',
        'notes', 'erp_id', 'erp_synced_at', 'erp_sync_status',
    ];

    protected $casts = [
        'amount'           => 'decimal:3',
        'is_driver_liable' => 'boolean',
        'is_deducted'      => 'boolean',
    ];

    public function employee(): BelongsTo    { return $this->belongsTo(Employee::class)->withTrashed(); }
    public function vehicle(): BelongsTo     { return $this->belongsTo(Vehicle::class)->withTrashed(); }
    public function createdBy(): BelongsTo   { return $this->belongsTo(User::class, 'created_by'); }
    public function payrollSlip(): BelongsTo { return $this->belongsTo(PayrollSlip::class); }
}
