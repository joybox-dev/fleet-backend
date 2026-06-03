<?php
declare(strict_types=1);

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleHandover extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'vehicle_id',
        'employee_id',
        'created_by',
        'company_id',
        'handover_date',
        'type',
        'odometer_reading',
        'photo_front',
        'photo_back',
        'photo_left',
        'photo_right',
        'scratches_details',
        'notes',
    ];

    protected $casts = [
        'handover_date' => 'datetime',
        'odometer_reading' => 'integer',
    ];

    /* ── Relationships ── */

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withTrashed();
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
