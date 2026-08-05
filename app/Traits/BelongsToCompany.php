<?php

namespace App\Traits;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Trait BelongsToCompany
 *
 * Apply to any model that should be scoped by the current company.
 * - Automatically filters all queries by company_id (global scope).
 * - Automatically sets company_id on new records.
 * - Provides a company() relationship.
 *
 * SECURITY: This is the core tenant-isolation mechanism.
 * The global scope ensures no cross-company data leaks in normal queries.
 * Use withoutGlobalScope('company') ONLY in super-admin contexts.
 */
trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        // ── Global scope: always filter by current company ──
        static::addGlobalScope('company', function (Builder $query) {
            $companyId = app()->bound('current_company_id')
                ? app('current_company_id')
                : 0;

            $query->where(
                $query->getModel()->getTable() . '.company_id',
                $companyId
            );
        });

        // ── Auto-set company_id on creation ──
        static::creating(function ($model) {
            if (!$model->company_id) {
                $companyId = app()->bound('current_company_id')
                    ? app('current_company_id')
                    : (auth()->user()?->company_id ?? 1);

                if ($companyId) {
                    $model->company_id = $companyId;
                }
            }
        });
    }

    /**
     * Get the company that owns this record.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Scope to a specific company (useful in super-admin queries).
     */
    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->withoutGlobalScope('company')
            ->where($this->getTable() . '.company_id', $companyId);
    }
}
