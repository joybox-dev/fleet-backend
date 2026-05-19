<?php

namespace App\Models;

use App\Traits\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class ImportLog extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'company_id',
        'user_id',
        'entity_type',
        'original_filename',
        'file_hash',
        'file_path',
        'column_mapping',
        'rows_total',
        'rows_imported',
        'rows_failed',
        'rows_skipped_duplicate',
        'status',
        'errors',
    ];

    protected $casts = [
        'column_mapping'         => 'array',
        'errors'                 => 'array',
        'rows_total'             => 'integer',
        'rows_imported'          => 'integer',
        'rows_failed'            => 'integer',
        'rows_skipped_duplicate' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
