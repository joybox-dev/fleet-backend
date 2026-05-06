<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('role', 30)->default('operator')->after('email');
            // Roles: admin, operator, accountant
        });

        // Set existing admin user
        \App\Models\User::where('email', 'admin@fleetops.com')->update(['role' => 'admin']);
        \App\Models\User::where('email', 'accountant@fleetops.com')->update(['role' => 'accountant']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
