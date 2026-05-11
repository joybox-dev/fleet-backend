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
    ];

    protected $casts = [
        'cash_collected' => 'decimal:3',
        'cash_settled'   => 'decimal:3',
        'cash_pending'   => 'decimal:3',
        'rate_per_order' => 'decimal:3',
        'income_amount'  => 'decimal:3',
    ];

    public function employee(): BelongsTo  { return $this->belongsTo(Employee::class); }
    public function vehicle(): BelongsTo   { return $this->belongsTo(Vehicle::class); }
    public function contract(): BelongsTo  { return $this->belongsTo(Contract::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
