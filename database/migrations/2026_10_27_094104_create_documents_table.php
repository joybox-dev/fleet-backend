<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One place for documents (client, 2026-10-27: «خليني اكتب اسم المستند وارفق الملف»): a name the
 * user writes, the file, and an optional expiry — belonging to an employee, a vehicle, or the company
 * itself. Employee documents used to pick their type from a fixed list of eight; they move here with
 * that type written out as their name, and keep it as `kind`. employee_documents is left as it was.
 */
return new class extends Migration
{
    private const TYPE_NAMES = [
        'passport' => 'جواز سفر',
        'civil_id' => 'بطاقة مدنية',
        'work_permit' => 'إذن عمل',
        'driving_license' => 'رخصة قيادة',
        'residence' => 'إقامة',
        'health_card' => 'كرت صحي',
        'contract' => 'عقد',
        'other' => 'مستند',
    ];

    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('owner_type', 20); // employee, vehicle, company
            $table->unsignedBigInteger('owner_id')->nullable(); // null for the company's own
            $table->string('title', 150);
            $table->string('kind', 40)->nullable(); // the fixed type it came with, for documents moved from employee_documents
            $table->string('document_number', 100)->nullable();
            $table->string('file_path', 500)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['company_id', 'owner_type', 'owner_id']);
            $table->index(['company_id', 'expiry_date']);
        });

        DB::table('employee_documents')->orderBy('id')->each(function ($d) {
            DB::table('documents')->insert([
                'company_id' => $d->company_id,
                'owner_type' => 'employee',
                'owner_id' => $d->employee_id,
                // With no type that fitted, people picked «other» and wrote the name in the notes
                // («استقاله», «توظيف») — that was its name all along.
                'title' => $d->document_type === 'other' && trim((string) $d->notes) !== ''
                    ? mb_substr(trim($d->notes), 0, 150)
                    : (self::TYPE_NAMES[$d->document_type] ?? $d->document_type),
                'kind' => $d->document_type,
                'document_number' => $d->document_number,
                'file_path' => $d->file_path,
                'issue_date' => $d->issue_date,
                'expiry_date' => $d->expiry_date,
                'notes' => $d->notes,
                'created_at' => $d->created_at,
                'updated_at' => $d->updated_at,
                'deleted_at' => $d->deleted_at,
            ]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
