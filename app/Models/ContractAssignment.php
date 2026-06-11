<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ContractAssignment extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'employee_id',
        'contract_id',
        'start_date',
        'end_date',
        'status',
        'courier_id'
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function overrides(): HasMany
    {
        return $this->hasMany(DriverContractOverride::class);
    }

    /**
     * Get the active override for a specific date (or today if none specified).
     */
    public function activeOverrideForDate($date = null)
    {
        $targetDate = $date ? \Carbon\Carbon::parse($date)->toDateString() : now()->toDateString();
        
        return $this->overrides()
            ->where('effective_from', '<=', $targetDate)
            ->where(function ($query) use ($targetDate) {
                $query->whereNull('effective_to')
                      ->orWhere('effective_to', '>=', $targetDate);
            })
            ->first();
    }
}
