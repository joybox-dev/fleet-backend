<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustodyType extends Model
{
    use BelongsToCompany;

    protected $fillable = ['name', 'icon'];

    public function custodyItems(): HasMany
    {
        return $this->hasMany(CustodyItem::class);
    }

    public function getDeletionBlocks(): array
    {
        $blocks = [];

        if ($this->custodyItems()->whereNull('returned_date')->exists()) {
            $blocks[] = 'لا يمكن حذف نوع العهدة لوجود عهد نشطة من هذا النوع مسلّمة للموظفين حالياً ولم تُسترجع بعد.';
        }

        return $blocks;
    }
}
