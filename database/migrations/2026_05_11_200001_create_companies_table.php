<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');                      // "الأسطول الأول"
            $table->string('name_ar')->nullable();
            $table->string('code')->unique();            // URL-safe slug: "fleet1"

            // ── Branding / Theming ──
            $table->string('logo_path')->nullable();
            $table->json('branding')->nullable();        // {primary_color, accent_color, sidebar_bg, ...}

            // ── Feature Gating ──
            $table->json('enabled_modules')->nullable(); // ["dashboard","employees","vehicles",...]

            // ── Company Info ──
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('address')->nullable();
            $table->string('tax_number')->nullable();
            $table->string('currency', 3)->default('KWD');
            $table->boolean('is_active')->default(true);
            $table->json('settings')->nullable();        // Company-specific overrides

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
