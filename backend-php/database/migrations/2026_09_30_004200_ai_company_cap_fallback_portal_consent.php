<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backlog 2, AI Assistant (Dennis, 2026-09-26, decision page):
 *
 * - The monthly token cap is PER COMPANY (companies.ai_monthly_token_cap),
 *   settling the two earlier answers; the installation total is still
 *   shown beside it. The one install-wide cap that was set is copied
 *   onto every company, so nothing that was capped becomes unlimited.
 *   ai_settings.monthly_token_cap is kept (never dropped) but no longer
 *   read.
 * - A fallback model (ai_settings.fallback_model): tried once when the
 *   main model fails or declines.
 * - The Helpdesk Portal's one-time AI declaration
 *   (portal_users.ai_data_consent_at), the customer's twin of the staff
 *   users.ai_data_consent_at: recorded once, never moved or cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->integer('ai_monthly_token_cap')->nullable();
        });
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->string('fallback_model', 60)->nullable();
        });
        Schema::table('portal_users', function (Blueprint $table) {
            $table->timestampTz('ai_data_consent_at')->nullable();
        });

        $cap = DB::table('ai_settings')->where('key', 'default')->value('monthly_token_cap');
        if ($cap !== null && (int) $cap > 0) {
            DB::table('companies')->update(['ai_monthly_token_cap' => (int) $cap]);
        }
    }

    public function down(): void
    {
        Schema::table('portal_users', function (Blueprint $table) {
            $table->dropColumn('ai_data_consent_at');
        });
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('fallback_model');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('ai_monthly_token_cap');
        });
    }
};
