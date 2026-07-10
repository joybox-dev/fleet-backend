<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VehicleType extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'name_ar',
    ];

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }
}
