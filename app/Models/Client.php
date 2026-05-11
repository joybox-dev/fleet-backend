<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use SoftDeletes, BelongsToCompany;

    protected $fillable = [
        'name', 'name_ar', 'contact_person', 'phone', 'email',
        'tax_number', 'is_active', 'erp_id', 'erp_synced_at', 'erp_sync_status',
    ];

    protected $casts = ['is_active' => 'boolean'];

    public function contracts(): HasMany
    {
        return $this->hasMany(Contract::class);
    }
}
