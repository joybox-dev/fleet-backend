<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'name',
        'allowed_modules'
    ];

    protected $casts = [
        'allowed_modules' => 'array'
    ];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class, 'admin_role_id');
    }
}
