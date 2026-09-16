<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A vehicle held by an authority — the interior ministry, traffic, the municipality — is neither
 * available nor in maintenance: it exists, nobody may use it, and it usually comes back on a date.
 * The owner asked for that state by name, with who holds it and until when, so the dashboard can
 * say when it is due back instead of the vehicle sitting under «idle» with the reason in a note.
 *
 * The status column was a database enum; a plain string with the same default lets the
 * application own the list of states (the controller validates it) without a table rebuild for
 * every state added later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('status', 20)->default('available')->change();
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('reserved_by', 120)->nullable()->after('status');
            $table->date('reserved_until')->nullable()->after('reserved_by');
            $table->string('reserved_note', 255)->nullable()->after('reserved_until');
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['reserved_by', 'reserved_until', 'reserved_note']);
        });
    }
};
