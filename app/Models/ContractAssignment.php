<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        'courier_id',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    /**
     * Assignments a driver is actually ON, a given day: flagged active AND the dates cover it.
     *
     * An assignment is ended by giving it an end date — the delete screen says so itself — and the
     * flag stays «active» afterwards, so the flag alone kept counting drivers who had left the
     * contract (28 of 105 on the client's books). Payroll has always gone by the dates; whatever
     * shows or counts «his current contract» asks the same question through here.
     */
    public function scopeCurrent(Builder $query, ?string $date = null): Builder
    {
        $day = $date ? Carbon::parse($date)->toDateString() : Carbon::now()->toDateString();

        return $query
            ->where($this->qualifyColumn('status'), 'active')
            ->whereDate($this->qualifyColumn('start_date'), '<=', $day)
            ->where(fn (Builder $q) => $q
                ->whereNull($this->qualifyColumn('end_date'))
                ->orWhereDate($this->qualifyColumn('end_date'), '>=', $day));
    }

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
        $targetDate = $date ? Carbon::parse($date)->toDateString() : now()->toDateString();

        return $this->overrides()
            ->where('effective_from', '<=', $targetDate)
            ->where(function ($query) use ($targetDate) {
                $query->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $targetDate);
            })
            ->first();
    }
}
