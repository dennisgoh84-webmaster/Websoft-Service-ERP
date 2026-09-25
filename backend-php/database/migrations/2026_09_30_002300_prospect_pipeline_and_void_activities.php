<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two confirmed Prospect / Leads rules (Dennis, 2026-09-26):
 *
 * - A prospect moves through pipeline stages New -> Qualified ->
 *   Proposal -> Negotiation, closing as Won or Lost. Every prospect
 *   still "open" under the earlier default starts at New.
 * - A prospect activity is never deleted; a mistaken one is voided,
 *   with a reason, and stays on record.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('prospects')->where('status', 'open')->update(['status' => 'new']);
        DB::statement("ALTER TABLE prospects ALTER COLUMN status SET DEFAULT 'new'");

        Schema::table('prospect_activities', function (Blueprint $table) {
            $table->text('void_reason')->nullable();
            $table->timestampTz('voided_at')->nullable();
            $table->uuid('voided_by_user_id')->nullable();
            $table->foreign('voided_by_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::table('prospect_activities', function (Blueprint $table) {
            $table->dropForeign(['voided_by_user_id']);
            $table->dropColumn(['void_reason', 'voided_at', 'voided_by_user_id']);
        });

        DB::statement("ALTER TABLE prospects ALTER COLUMN status SET DEFAULT 'open'");
        DB::table('prospects')->whereIn('status', ['new', 'qualified', 'proposal', 'negotiation'])->update(['status' => 'open']);
    }
};
