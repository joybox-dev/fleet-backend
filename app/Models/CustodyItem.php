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

    protected static function booted()
    {
        static::creating(function ($item) {
            if (empty($item->item_type) && !empty($item->custody_type_id)) {
                $typeMap = [
                    1 => 'phone',
                    2 => 'sim',
                    3 => 'clothing',
                    4 => 'cash',
                    5 => 'other',
                ];
                $item->item_type = $typeMap[$item->custody_type_id] ?? 'other';
            }
        });

        static::saved(function ($item) {
            self::recalculatePayroll($item->employee_id, $item->returned_date ?: $item->issued_date);
        });
        static::deleted(function ($item) {
            self::recalculatePayroll($item->employee_id, $item->returned_date ?: $item->issued_date);
        });
    }

    private static function recalculatePayroll($employeeId, $dateStr): void
    {
        try {
            $date = \Carbon\Carbon::parse($dateStr);
            $run = \App\Models\PayrollRun::where('year', $date->year)
                ->where('month', $date->month)
                ->where('status', 'draft')
                ->first();
            if ($run) {
                \App\Http\Controllers\Api\PayrollController::recalculateRun($run);
            }
        } catch (\Throwable $e) {
            \Log::error("Recalculate draft payroll failed in CustodyItem: " . $e->getMessage());
        }
    }

    public function employee(): BelongsTo { return $this->belongsTo(Employee::class); }
    public function issuedBy(): BelongsTo { return $this->belongsTo(User::class, 'issued_by'); }
    public function custodyType(): BelongsTo { return $this->belongsTo(CustodyType::class); }
}
