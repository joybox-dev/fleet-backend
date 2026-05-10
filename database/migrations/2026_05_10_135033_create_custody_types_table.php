<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custody_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('icon')->nullable();
            $table->timestamps();
        });

        // Seed default types
        DB::table('custody_types')->insert([
            ['name' => 'هاتف',   'icon' => '📱', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'شريحة',  'icon' => '💳', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'زي',     'icon' => '👕', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'كاش',    'icon' => '💵', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'أخرى',   'icon' => '📦', 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Add custody_type_id to custody_items
        Schema::table('custody_items', function (Blueprint $table) {
            $table->foreignId('custody_type_id')->nullable()->after('item_type')->constrained('custody_types')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('custody_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('custody_type_id');
        });
        Schema::dropIfExists('custody_types');
    }
};
