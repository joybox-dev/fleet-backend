<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleAssignment extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'vehicle_id', 'employee_id', 'contract_id',
        'assigned_date', 'unassigned_date', 'is_active', 'notes',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function vehicle(): BelongsTo   { return $this->belongsTo(Vehicle::class); }
    public function employee(): BelongsTo  { return $this->belongsTo(Employee::class); }
    public function contract(): BelongsTo  { return $this->belongsTo(Contract::class); }
}
