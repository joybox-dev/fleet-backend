<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustodyType extends Model
{
    protected $fillable = ['name', 'icon'];

    public function custodyItems(): HasMany
    {
        return $this->hasMany(CustodyItem::class);
    }
}
