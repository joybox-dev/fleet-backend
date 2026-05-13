<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeDocument extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'employee_id', 'document_type', 'document_number',
        'file_path', 'issue_date', 'expiry_date',
        'status', 'notes',
    ];

    protected $casts = [
        'issue_date'  => 'date',
        'expiry_date' => 'date',
    ];

    public const TYPES = [
        'passport', 'civil_id', 'work_permit', 'driving_license',
        'residence', 'health_card', 'contract', 'other',
    ];

    /* ── Relationships ── */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /* ── Helpers ── */

    /**
     * Auto-set status based on expiry date.
     */
    public function refreshStatus(): void
    {
        if (!$this->expiry_date) {
            $this->status = 'valid';
            return;
        }

        $daysUntilExpiry = now()->diffInDays($this->expiry_date, false);

        if ($daysUntilExpiry < 0) {
            $this->status = 'expired';
        } elseif ($daysUntilExpiry <= 30) {
            $this->status = 'pending_renewal';
        } else {
            $this->status = 'valid';
        }
    }
}
