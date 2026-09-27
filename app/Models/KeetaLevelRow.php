<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One rider of a Keeta «expected level» export: tier S to D (null when Keeta has not ranked him),
 * the incentive the tier pays, and the five measures Keeta ranks on.
 */
class KeetaLevelRow extends Model
{
    protected $fillable = [
        'keeta_level_snapshot_id', 'courier_id', 'employee_id', 'name', 'level', 'reward', 'ontime_rate',
        'completion_rate', 'utr', 'orders', 'acceptance_rate',
    ];

    protected $casts = [
        'reward' => 'decimal:3',
        'ontime_rate' => 'decimal:4',
        'completion_rate' => 'decimal:4',
        'utr' => 'decimal:4',
        'orders' => 'integer',
        'acceptance_rate' => 'decimal:4',
    ];

    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(KeetaLevelSnapshot::class, 'keeta_level_snapshot_id');
    }
}
