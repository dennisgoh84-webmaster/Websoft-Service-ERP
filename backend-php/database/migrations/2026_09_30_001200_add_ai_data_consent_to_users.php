<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PDPA self-declaration for the AI Assistant (Dennis, 2026-09-15,
 * settling the last part of decision 12.1): every staff user must
 * acknowledge, at login, that their queries may be sent to Anthropic's
 * US-hosted API (masked by default) and that non-sensitive usage data
 * may be analysed internally, before they can use the system at all.
 * Recorded once, the first time -- `users.ai_data_consent_at` is
 * deliberately NOT in User::$fillable (see the model) and never
 * appears in UserController::update()'s validated fields, so no
 * screen, including Staff Master's own edit form, can ever clear or
 * back-date it; only App\Http\Controllers\Api\AuthController::
 * acknowledgeAiConsent() sets it, and only when it is still null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('ai_data_consent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ai_data_consent_at');
        });
    }
};
