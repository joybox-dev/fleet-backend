<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An employee may be on file before anyone knows the day he joined. The Excel importer offered
     * the joining date as optional while this column refused a row without it, so the client's
     * first import lost all 106 of its employees to the same database error. The date stays
     * required on the entry form, where someone is there to ask; a bulk file takes what it is given
     * and the gap is filled later.
     */
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->date('date_of_joining')->nullable()->change();
        });
    }

    /**
     * Not reversed: rows imported without a date would make the column impossible to tighten
     * again, and inventing a date for them is worse than leaving the column as it is.
     */
    public function down(): void
    {
        //
    }
};
