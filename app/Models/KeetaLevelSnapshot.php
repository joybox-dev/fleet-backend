<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Keeta's «expected level» export for a month, as uploaded on a given day: each rider's current
 * tier and the incentive that tier would pay. The newest one of a month is the one the estimate reads.
 */
class KeetaLevelSnapshot extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'contract_id', 'year', 'month', 'taken_on', 'original_filename', 'imported_by'];

    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'taken_on' => 'date',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(KeetaLevelRow::class);
    }

    public function importer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by');
    }
}
