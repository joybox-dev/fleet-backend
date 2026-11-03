<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A document with the name the user wrote, its file, and an optional expiry — belonging to an
 * employee, a vehicle, or the company itself (owner_id null).
 */
class Document extends Model
{
    use BelongsToCompany, SoftDeletes;

    public const OWNER_TYPES = ['employee', 'vehicle', 'company'];

    /** A document is «due» when it lapses within this many days. */
    public const DUE_WITHIN_DAYS = 30;

    protected $fillable = [
        'owner_type', 'owner_id', 'title', 'kind', 'document_number',
        'file_path', 'issue_date', 'expiry_date', 'notes', 'created_by',
    ];

    protected $casts = [
        'owner_id' => 'integer',
        'issue_date' => 'date:Y-m-d',
        'expiry_date' => 'date:Y-m-d',
    ];

    protected $appends = ['status', 'days_left'];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'owner_id');
    }

    /** Days until it lapses; negative once it has; null when it has no expiry. */
    public function getDaysLeftAttribute(): ?int
    {
        return $this->expiry_date ? (int) Carbon::today()->diffInDays($this->expiry_date, false) : null;
    }

    /** expired, due (within a month), valid, or no_expiry. */
    public function getStatusAttribute(): string
    {
        $days = $this->days_left;

        return match (true) {
            $days === null => 'no_expiry',
            $days < 0 => 'expired',
            $days <= self::DUE_WITHIN_DAYS => 'due',
            default => 'valid',
        };
    }
}
