<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remote-controlled upgrades (Dennis, 2026-09-24: "do for CC push to
 * client side upgrade"). The application never upgrades itself -- the
 * code runs inside Docker images, so nothing in a container can pull
 * new code or rebuild. Instead:
 *
 *   - `upgrade_requests` is a queue. Central Command inserts a row
 *     straight into this table (the same direct-DB pattern as every
 *     other push -- see docs/central-command-schema-contract.md).
 *   - deploy/upgrade-agent.sh, a systemd timer on the HOST, polls this
 *     app once a minute (POST /api/system/upgrade-agent/heartbeat),
 *     takes the next pending row, runs deploy/upgrade.sh <target_ref>,
 *     and reports the outcome back.
 *   - `upgrade_agent_state` is the single row that heartbeat keeps
 *     fresh: what commit is checked out, what origin/main is at, and
 *     when the agent was last seen. Central Command reads it to show
 *     "current / latest / behind by N".
 *
 * The database is never restored by an upgrade or a rollback -- only
 * migrated forward (deploy/upgrade.sh takes a dump first, and prints
 * the restore command for a human to decide on).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upgrade_agent_state', function (Blueprint $table) {
            // Singleton: id is always 1.
            $table->smallInteger('id')->primary();
            $table->string('current_sha', 40)->nullable();
            $table->text('current_subject')->nullable();
            $table->timestampTz('current_committed_at')->nullable();
            $table->string('remote_sha', 40)->nullable();
            $table->text('remote_subject')->nullable();
            $table->timestampTz('remote_committed_at')->nullable();
            $table->integer('commits_behind')->default(0);
            $table->string('agent_host', 200)->nullable();
            $table->timestampTz('last_heartbeat_at')->nullable();
        });

        Schema::create('upgrade_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // 'upgrade' or 'rollback' -- only a label; both check out target_ref.
            $table->string('kind', 10)->default('upgrade');
            // A commit sha, tag or branch handed to deploy/upgrade.sh.
            $table->string('target_ref', 80);
            // pending -> running -> succeeded | failed; or cancelled while pending.
            $table->string('status', 10)->default('pending');
            // Free text: 'central-command:<admin uuid>' or a local user id.
            $table->string('requested_by', 200)->nullable();
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->string('from_sha', 40)->nullable();
            $table->string('to_sha', 40)->nullable();
            $table->text('log')->nullable();
            $table->text('error')->nullable();
            $table->index(['status', 'requested_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upgrade_requests');
        Schema::dropIfExists('upgrade_agent_state');
    }
};
