<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A node of the company's expense tree: a category (no parent) or an item under one. Company
 * expenses are recorded against an item; the profit-and-loss groups them by category.
 */
class ExpenseCategory extends Model
{
    use BelongsToCompany;

    protected $fillable = ['company_id', 'parent_id', 'name_ar', 'code', 'sort_order', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * The tree a company starts with: the accountant's list of 2026-10, a category apart from the
     * vehicles' running costs (which keep their own screen and types).
     *
     * @var array<string, array<int, string>>
     */
    public const DEFAULT_TREE = [
        'موارد بشرية وإدارية' => [
            'شؤون وجوازات', 'تجديد إقامات', 'إقامات جديدة', 'كروت صحة', 'تذاكر سفر',
            'إيجار سكن', 'إيجار مكتب', 'طباعة', 'بوفيه ونظافة', 'مصاريف تعاقدية', 'رسوم بنكية', 'تراخيص الشركة',
        ],
        'مركبات (غير تشغيلية)' => ['إيجار سيارات', 'تأمين سيارات', 'تراخيص سيارات'],
        'أخرى' => ['مصاريف متنوعة'],
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort_order')->orderBy('id');
    }

    /** «الفئة › البند», or the category's own name. */
    public function path(): string
    {
        return $this->parent ? $this->parent->name_ar.' › '.$this->name_ar : $this->name_ar;
    }

    /** Seeds the default tree the first time a company opens it; never touches a tree it has. */
    public static function ensureDefaults(int $companyId): void
    {
        if (self::withoutGlobalScopes()->where('company_id', $companyId)->exists()) {
            return;
        }

        $order = 0;
        foreach (self::DEFAULT_TREE as $category => $items) {
            $parent = self::withoutGlobalScopes()->create([
                'company_id' => $companyId, 'name_ar' => $category, 'sort_order' => $order++,
            ]);
            foreach ($items as $i => $item) {
                self::withoutGlobalScopes()->create([
                    'company_id' => $companyId, 'parent_id' => $parent->id, 'name_ar' => $item, 'sort_order' => $i,
                ]);
            }
        }
    }
}
