<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ImportLog extends Model
{
    use BelongsToCompany;

    /** Existing records are skipped; only new ones are written. */
    public const MODE_CREATE = 'create';

    /** Existing records take the cells the file fills in; new ones are written as well. */
    public const MODE_UPSERT = 'upsert';

    protected $fillable = [
        'company_id',
        'user_id',
        'entity_type',
        'mode',
        'original_filename',
        'file_hash',
        'file_path',
        'column_mapping',
        'rows_total',
        'rows_imported',
        'rows_updated',
        'rows_failed',
        'rows_skipped_duplicate',
        'status',
        'errors',
    ];

    protected $attributes = [
        'mode' => self::MODE_CREATE,
    ];

    protected $casts = [
        'column_mapping' => 'array',
        'errors' => 'array',
        'rows_total' => 'integer',
        'rows_imported' => 'integer',
        'rows_updated' => 'integer',
        'rows_failed' => 'integer',
        'rows_skipped_duplicate' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
