<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashSettlement extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'employee_id', 'daily_log_id', 'received_by',
        'settlement_date', 'amount', 'receipt_photo_path', 'notes',
        'erp_id', 'erp_synced_at', 'erp_sync_status',
    ];

    protected $casts = ['amount' => 'decimal:3'];

    public function employee(): BelongsTo  { return $this->belongsTo(Employee::class); }
    public function dailyLog(): BelongsTo  { return $this->belongsTo(DailyLog::class); }
    public function receivedBy(): BelongsTo{ return $this->belongsTo(User::class, 'received_by'); }
}
