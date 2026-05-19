<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('company_id');
            $table->unsignedBigInteger('user_id');
            $table->string('entity_type');                // employees, vehicles
            $table->string('original_filename');
            $table->string('file_path')->nullable();
            $table->json('column_mapping')->nullable();   // user's column mapping
            $table->integer('rows_total')->default(0);
            $table->integer('rows_imported')->default(0);
            $table->integer('rows_failed')->default(0);
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])->default('pending');
            $table->json('errors')->nullable();           // per-row errors
            $table->timestamps();

            $table->index('company_id');
            $table->index('user_id');
            $table->index('entity_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_logs');
    }
};
