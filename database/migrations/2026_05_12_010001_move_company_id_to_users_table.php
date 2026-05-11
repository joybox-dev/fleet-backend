<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Simplify: each user belongs to exactly ONE company.
 * Move company_id from pivot table to users table directly.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Add company_id to users (nullable for super admin)
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('company_id')->nullable()->after('is_super_admin')
                ->constrained()->nullOnDelete();
        });

        // Backfill from pivot table
        $pivots = DB::table('company_user')->get();
        foreach ($pivots as $pivot) {
            DB::table('users')
                ->where('id', $pivot->user_id)
                ->update(['company_id' => $pivot->company_id]);
        }

        // Drop pivot table — no longer needed
        Schema::dropIfExists('company_user');
    }

    public function down(): void
    {
        // Recreate pivot
        Schema::create('company_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role')->default('operator');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['company_id', 'user_id']);
        });

        // Move data back
        $users = DB::table('users')->whereNotNull('company_id')->get();
        foreach ($users as $user) {
            DB::table('company_user')->insert([
                'company_id' => $user->company_id,
                'user_id'    => $user->id,
                'role'       => $user->role ?? 'operator',
                'is_default' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['company_id']);
            $table->dropColumn('company_id');
        });
    }
};
