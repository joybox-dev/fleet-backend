<?php

namespace App\Traits;

use App\Models\OperationalAdvance;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A record paid out of an operational custody (see CustodySpendingService): the custody, and through
 * it the administrative employee who paid.
 */
trait PaidFromCustody
{
    public function custody(): BelongsTo
    {
        return $this->belongsTo(OperationalAdvance::class, 'operational_advance_id')->withoutGlobalScopes();
    }
}
