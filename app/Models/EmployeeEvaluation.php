<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EmployeeEvaluation extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'employee_id', 'evaluator_id',
        'evaluation_date', 'period_from', 'period_to',
        'overall_score', 'status', 'notes',
    ];

    protected $casts = [
        'evaluation_date' => 'date',
        'period_from'     => 'date',
        'period_to'       => 'date',
        'overall_score'   => 'decimal:2',
    ];

    /* ── Relationships ── */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function evaluator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluator_id');
    }

    public function scores(): HasMany
    {
        return $this->hasMany(EvaluationScore::class, 'evaluation_id');
    }

    /* ── Helpers ── */

    /**
     * Calculate weighted average from individual scores.
     */
    public function calculateOverallScore(): float
    {
        $scores = $this->scores()->with('criterion')->get();

        if ($scores->isEmpty()) {
            return 0;
        }

        $totalWeight = $scores->sum(fn($s) => (float) $s->criterion->weight);

        if ($totalWeight == 0) {
            return $scores->avg('score');
        }

        $weighted = $scores->sum(fn($s) => (float) $s->score * (float) $s->criterion->weight);

        return round($weighted / $totalWeight, 2);
    }
}
