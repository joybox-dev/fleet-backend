<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('status_reason')->nullable()->after('status');
            $table->boolean('has_whatsapp')->default(false)->after('phone');
            $table->string('whatsapp_company_number')->nullable()->after('has_whatsapp');
        });
    }

    public function down()
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn(['status_reason', 'has_whatsapp', 'whatsapp_company_number']);
        });
    }
};
