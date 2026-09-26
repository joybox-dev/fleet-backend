<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class VehicleExpense extends Model
{
    use BelongsToCompany, HasFactory, SoftDeletes;

    protected $fillable = [
        'vehicle_id',
        'expense_type',
        'amount',
        'expense_date',
        'vendor',
        'receipt_path',
        'description',
        'notes',
        'company_id',
    ];

    protected $casts = [
        'amount' => 'decimal:3',
        'expense_date' => 'date',
    ];

    /**
     * The words the screens show for the codes the form stores. A type added in the settings is
     * stored by its own name and shown as it is.
     */
    public const TYPE_LABELS = [
        'fuel' => 'وقود',
        'insurance' => 'تأمين',
        'tires' => 'إطارات',
        'registration' => 'ترخيص',
        'fine' => 'غرامة',
        'repair' => 'إصلاح',
        'other' => 'أخرى',
    ];

    public static function typeLabel(?string $type): string
    {
        return self::TYPE_LABELS[$type] ?? ($type ?: 'مصروف مركبة');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }
}
