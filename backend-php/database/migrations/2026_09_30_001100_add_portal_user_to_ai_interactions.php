<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Assistant slice 3 (2026-09-15): the chat panel on the Customer
 * Helpdesk Portal. `ai_interactions.user_id` is a foreign key to the
 * staff `users` table, so a portal-originated call needs its own
 * column rather than overloading that one -- exactly the reasoning
 * `login_otps.portal_user_id` / `incidents.raised_by_portal_user_id`
 * followed when the Portal module landed (see those migrations).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table) {
            $table->uuid('portal_user_id')->nullable()->after('user_id');
            $table->foreign('portal_user_id')->references('id')->on('portal_users');
        });
    }

    public function down(): void
    {
        Schema::table('ai_interactions', function (Blueprint $table) {
            $table->dropForeign(['portal_user_id']);
            $table->dropColumn('portal_user_id');
        });
    }
};
