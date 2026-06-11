<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupervisorAllocationAuditLog extends Model
{
    protected $table = 'supervisor_allocation_audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'employee_id',
        'action_by',
        'old_allocation',
        'new_allocation',
        'changed_at'
    ];

    protected $casts = [
        'old_allocation' => 'array',
        'new_allocation' => 'array',
        'changed_at' => 'datetime'
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'action_by');
    }
}
