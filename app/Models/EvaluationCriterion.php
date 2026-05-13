<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EvaluationCriterion extends Model
{
    use BelongsToCompany;

    protected $table = 'evaluation_criteria';

    protected $fillable = [
        'name', 'name_ar', 'weight', 'is_active',
    ];

    protected $casts = [
        'weight'    => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function scores(): HasMany
    {
        return $this->hasMany(EvaluationScore::class, 'criterion_id');
    }
}
