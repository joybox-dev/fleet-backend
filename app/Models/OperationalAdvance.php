<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OperationalAdvance extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'employee_id',
        'amount',
        'date',
        'reason',
        'status',
        'approved_by'
    ];

    protected $casts = [
        'amount' => 'decimal:3',
        'date' => 'date',
        'approved_by' => 'integer'
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function expenses(): HasMany
    {
        return $this->hasMany(OperationalAdvanceExpense::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(OperationalAdvanceReturn::class);
    }
}
