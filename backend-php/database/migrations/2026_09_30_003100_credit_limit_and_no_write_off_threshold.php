<?php

use App\Services\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dennis, 2026-09-26:
 *
 * - "I think can totally remove this write off approval amount" -- the
 *   Company Setup write-off threshold goes; a write-off is the owner's,
 *   always (what already applied while no amount was set). A value that
 *   had been set is recorded in Event Logs before the column goes.
 * - The customer credit limit and the credit note approval limit are
 *   "2 separate matter and settings, all in company/individual file":
 *   credit_limit_sgd joins the credit note limit already there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_individuals', function (Blueprint $table) {
            $table->decimal('credit_limit_sgd', 14, 2)->nullable();
        });

        foreach (DB::table('companies')->whereNotNull('write_off_approval_threshold_sgd')->get(['id', 'write_off_approval_threshold_sgd']) as $c) {
            Audit::record(
                entityType: 'company',
                entityId: $c->id,
                action: 'write_off_threshold_removed',
                actorUserId: null,
                actorName: 'System (migration 2026_09_30_003100)',
                companyId: $c->id,
                details: 'Write-off approval threshold removed; every write-off is now the owner\'s',
                oldValue: ['write_off_approval_threshold_sgd' => (string) $c->write_off_approval_threshold_sgd],
            );
        }
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn('write_off_approval_threshold_sgd');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('write_off_approval_threshold_sgd', 12, 2)->nullable();
        });
        Schema::table('company_individuals', function (Blueprint $table) {
            $table->dropColumn('credit_limit_sgd');
        });
    }
};
