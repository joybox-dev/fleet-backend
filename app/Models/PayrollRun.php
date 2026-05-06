<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PayrollRun extends Model
{
    protected $fillable = [
        'created_by', 'year', 'month', 'status',
        'approved_at', 'approved_by',
        'total_official', 'total_actual', 'total_cash_diff', 'notes',
    ];

    protected $casts = [
        'total_official'  => 'decimal:3',
        'total_actual'    => 'decimal:3',
        'total_cash_diff' => 'decimal:3',
    ];

    public function slips(): HasMany      { return $this->hasMany(PayrollSlip::class); }
    public function createdBy(): BelongsTo{ return $this->belongsTo(User::class, 'created_by'); }
    public function approvedBy(): BelongsTo{ return $this->belongsTo(User::class, 'approved_by'); }
}
