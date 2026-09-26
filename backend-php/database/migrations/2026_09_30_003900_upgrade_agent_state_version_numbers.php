<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The ERP's version number (2026-09-26), e.g. "1.0.214", for the code
 * running here and for the latest on main, as the upgrade agent works
 * them out (deploy/version.sh). Central Command's Client Upgrades shows
 * them in the same wording as this install's login screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('upgrade_agent_state', function (Blueprint $table) {
            $table->string('current_version', 40)->nullable();
            $table->string('remote_version', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('upgrade_agent_state', function (Blueprint $table) {
            $table->dropColumn(['current_version', 'remote_version']);
        });
    }
};
