<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DailyLog extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'employee_id', 'vehicle_id', 'contract_id', 'created_by', 'log_date',
        'orders_count', 'orders_online', 'orders_cash',
        'cash_collected', 'cash_settled', 'cash_pending',
        'rate_per_order', 'income_amount',
        'odometer_start', 'odometer_end', 'notes',
        'erp_id', 'erp_synced_at', 'erp_sync_status',
        'driver_commission',
        
        // Keeta and contract specific daily metrics
        'shift_valid',
        'online_hours',
        'ontime_rate',
        'avg_delivery_time'
    ];

    protected $casts = [
        'cash_collected'    => 'decimal:3',
        'cash_settled'      => 'decimal:3',
        'cash_pending'      => 'decimal:3',
        'rate_per_order'    => 'decimal:3',
        'income_amount'     => 'decimal:3',
        'driver_commission' => 'decimal:3',
        'shift_valid'       => 'boolean',
        'online_hours'      => 'decimal:2',
        'ontime_rate'       => 'decimal:2',
        'avg_delivery_time' => 'integer',
    ];

    public function employee(): BelongsTo  { return $this->belongsTo(Employee::class)->withTrashed(); }
    public function vehicle(): BelongsTo   { return $this->belongsTo(Vehicle::class)->withTrashed(); }
    public function contract(): BelongsTo  { return $this->belongsTo(Contract::class)->withTrashed(); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
