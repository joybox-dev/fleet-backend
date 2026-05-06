<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustodyItem extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'employee_id', 'issued_by', 'item_type', 'item_description',
        'serial_number', 'value', 'issued_date', 'returned_date',
        'is_returned', 'return_condition', 'deduction_amount', 'notes',
        'erp_id', 'erp_synced_at', 'erp_sync_status',
    ];

    protected $casts = [
        'value'            => 'decimal:3',
        'deduction_amount' => 'decimal:3',
        'is_returned'      => 'boolean',
    ];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function issuedBy(): BelongsTo { return $this->belongsTo(User::class, 'issued_by'); }
}
