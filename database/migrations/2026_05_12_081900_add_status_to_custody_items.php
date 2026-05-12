<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('custody_items', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('is_returned');
        });

        // Backfill: is_returned=true → 'returned', else 'active'
        DB::table('custody_items')->where('is_returned', true)->update(['status' => 'returned']);
        DB::table('custody_items')->where('is_returned', false)->update(['status' => 'active']);

        // Drop the old boolean column
        Schema::table('custody_items', function (Blueprint $table) {
            $table->dropColumn('is_returned');
        });
    }

    public function down(): void
    {
        Schema::table('custody_items', function (Blueprint $table) {
            $table->boolean('is_returned')->default(false)->after('returned_date');
        });

        DB::table('custody_items')->where('status', 'returned')->update(['is_returned' => true]);
        DB::table('custody_items')->where('status', '!=', 'returned')->update(['is_returned' => false]);

        Schema::table('custody_items', function (Blueprint $table) {
            $table->dropColumn('status');
        });
    }
};
