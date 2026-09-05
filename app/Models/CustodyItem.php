<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CustodyItem extends Model
{
    use BelongsToCompany, SoftDeletes;

    protected $fillable = [
        'employee_id', 'issued_by', 'item_type', 'custody_type_id', 'item_description',
        'serial_number', 'value', 'issued_date', 'returned_date',
        'status', 'return_condition', 'deduction_amount', 'notes',
        'handover_proof_path', 'return_proof_path',
        'erp_id', 'erp_synced_at', 'erp_sync_status',
    ];

    protected $casts = [
        'value' => 'decimal:3',
        'deduction_amount' => 'decimal:3',
    ];

    /**
     * The legacy `item_type` enum predates the custody_types table and is still NOT NULL, so it has
     * to be filled in from the chosen type.
     *
     * It used to be read off the raw custody_type_id against a hardcoded 1..5 map — the ids the
     * install migration seeded for the very first company. Every other company's types fell outside
     * that range and were all filed as "other", and a company whose type happened to land on id 2
     * had its uniforms recorded as SIM cards. Matching on the type's name instead is company-blind:
     * a company that names its own type "هاتف" gets phone, wherever its id landed.
     */
    private const LEGACY_TYPE_BY_NAME = [
        'هاتف' => 'phone',
        'موبايل' => 'phone',
        'شريحة' => 'sim',
        'خط' => 'sim',
        'زي' => 'clothing',
        'زي رسمي' => 'clothing',
        'ملابس' => 'clothing',
        'كاش' => 'cash',
        'نقد' => 'cash',
        'نقدية' => 'cash',
    ];

    protected static function booted()
    {
        static::creating(function ($item) {
            if (! empty($item->item_type)) {
                return;
            }

            $name = $item->custody_type_id
                ? CustodyType::withoutGlobalScopes()->whereKey($item->custody_type_id)->value('name')
                : null;

            // Always land on a valid enum value: the column is NOT NULL and an item with no type at
            // all used to fail the insert outright.
            $item->item_type = self::LEGACY_TYPE_BY_NAME[trim((string) $name)] ?? 'other';
        });
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function custodyType(): BelongsTo
    {
        return $this->belongsTo(CustodyType::class);
    }
}
