<?php

namespace App\Validation;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `exists_in_company:table,column` — the record exists AND belongs to the company the request is
 * working in.
 *
 * Laravel's `exists:` asks the whole database, so a request could link a contract to another
 * company's client, a vehicle to another company's vehicle type, or a login to another company's
 * role, as long as the id was real somewhere. The screens only ever list the company's own
 * records, so this never showed in use; it matters the day a second company shares the system.
 * A table without a company_id column, or a request with no company bound, falls back to the
 * plain existence check.
 */
class ExistsInCompany
{
    /** @var array<string, bool> */
    private static array $tenantTables = [];

    /**
     * @param  array<int, string>  $parameters
     */
    public static function validate(string $attribute, mixed $value, array $parameters): bool
    {
        $table = $parameters[0] ?? null;
        $column = $parameters[1] ?? 'id';
        if ($table === null) {
            return false;
        }

        $values = array_values(array_unique(array_map('strval', is_array($value) ? $value : [$value])));
        if ($values === []) {
            return false;
        }

        $query = DB::table($table)->whereIn($column, $values);
        if (app()->bound('current_company_id') && self::isTenantTable($table)) {
            $query->where('company_id', (int) app('current_company_id'));
        }

        return $query->distinct()->count($column) === count($values);
    }

    private static function isTenantTable(string $table): bool
    {
        return self::$tenantTables[$table] ??= Schema::hasColumn($table, 'company_id');
    }
}
