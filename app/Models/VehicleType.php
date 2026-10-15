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

    /**
     * Every company works with these three; one that has none is given them the first time any
     * screen or rule needs its types, and one that lost its large car gets it back.
     */
    public static function ensureDefaults(int $companyId): void
    {
        $own = self::withoutGlobalScopes()->where('company_id', $companyId);

        if (! (clone $own)->exists()) {
            foreach ([['Motorcycle', 'سيكل / دراجة نارية'], ['Small Car', 'سيارة صغيرة'], ['Large Car', 'سيارة كبيرة']] as [$name, $nameAr]) {
                self::withoutGlobalScopes()->forceCreate(['company_id' => $companyId, 'name' => $name, 'name_ar' => $nameAr]);
            }

            return;
        }

        if (! (clone $own)->where('name_ar', 'like', '%كبيرة%')->exists()) {
            self::withoutGlobalScopes()->forceCreate(['company_id' => $companyId, 'name' => 'Large Car', 'name_ar' => 'سيارة كبيرة']);
        }
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }
}
