<?php

use App\Services\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Software Task statuses (Dennis, 2026-09-26, decisions 12.1 / #50):
 * Open -> Programming -> For Testing -> Tested -> Released.
 *
 * Existing tasks land "by what they show today": tested ones become
 * Tested; untested ones whose finish date has passed become For
 * Testing; the rest become Open. Each move is written to Event Logs.
 * `is_tested` stays (Support Monitoring reads it) and is kept in step:
 * true for Tested and Released.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('software_tasks', function (Blueprint $table) {
            $table->string('status', 20)->default('open');
            $table->timestampTz('status_changed_at')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->uuid('released_by_user_id')->nullable();
            $table->foreign('released_by_user_id')->references('id')->on('users');
            $table->index(['company_id', 'status']);
        });

        $today = Carbon::today()->toDateString();
        foreach (DB::table('software_tasks')->get() as $t) {
            $status = $t->is_tested ? 'tested'
                : ($t->programming_finish_date !== null && $t->programming_finish_date < $today ? 'for_testing' : 'open');
            DB::table('software_tasks')->where('id', $t->id)->update(['status' => $status, 'status_changed_at' => Carbon::now()]);
            Audit::record(
                entityType: 'software_task',
                entityId: $t->id,
                action: 'status_set_by_migration',
                actorUserId: null,
                actorName: 'System (migration 2026_09_30_003700)',
                companyId: $t->company_id,
                details: "Software Task statuses introduced: \"{$t->title}\" starts as {$status}",
                newValue: ['status' => $status],
            );
        }
    }

    public function down(): void
    {
        Schema::table('software_tasks', function (Blueprint $table) {
            $table->dropForeign(['released_by_user_id']);
            $table->dropIndex(['company_id', 'status']);
            $table->dropColumn(['status', 'status_changed_at', 'released_at', 'released_by_user_id']);
        });
    }
};
