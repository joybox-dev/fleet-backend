<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustodyItem extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'employee_id', 'issued_by', 'item_type', 'custody_type_id', 'item_description',
        'serial_number', 'value', 'issued_date', 'returned_date',
        'status', 'return_condition', 'deduction_amount', 'notes',
        'erp_id', 'erp_synced_at', 'erp_sync_status',
    ];

    protected $casts = [
        'value'            => 'decimal:3',
        'deduction_amount' => 'decimal:3',
    ];

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function issuedBy(): BelongsTo { return $this->belongsTo(User::class, 'issued_by'); }
    public function custodyType(): BelongsTo { return $this->belongsTo(CustodyType::class); }
}
