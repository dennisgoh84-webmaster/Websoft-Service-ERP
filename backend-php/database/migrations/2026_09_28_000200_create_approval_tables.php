<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * eApproval Master (planned-work.md #4). Mirrors
 * backend/app/models/approvals.py.
 *
 * Four tables, and the split between them is the design:
 *   - an AUTHORITY is a named group of people who may approve something
 *     ("PO Approval", "Bank Authority for DBS Current Account"), and
 *     carries whether ANY ONE of them suffices or ALL must agree;
 *   - its MEMBERS are those people;
 *   - a RULE binds an authority to a document type, optionally above a
 *     value threshold, with a priority;
 *   - a REQUEST is one document actually awaiting that rule, and its
 *     DECISIONS are the individual approvers' answers.
 *
 * Requests and decisions are never deleted. A resolved request keeps
 * its decisions, which is what makes "what was approved, by whom, and
 * when" answerable after the fact -- planned-work #4 calls this out
 * explicitly, because today's Service Record approval screen drops an
 * item off the list once acted on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('approval_authorities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('name', 200);
            $table->string('description', 500)->nullable();
            // any_one | all_must
            $table->string('mode', 20)->default('any_one');
            // Set when this authority governs a specific bank account
            // ("Bank Authority"), the concept planned-work #4 introduces
            // as distinct from Group Authority's module-level access.
            $table->uuid('bank_account_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts');
            $table->unique(['company_id', 'name'], 'uq_approval_authority_name');
        });

        Schema::create('approval_authority_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('authority_id');
            $table->uuid('user_id');
            $table->timestampTz('added_at')->useCurrent();

            $table->foreign('authority_id')->references('id')->on('approval_authorities');
            $table->foreign('user_id')->references('id')->on('users');
            $table->unique(['authority_id', 'user_id'], 'uq_approval_authority_member');
        });

        Schema::create('approval_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('authority_id');
            // One of DocumentEntityType -- see App\Support\DocumentEntityType.
            $table->string('entity_type', 40);
            // Null means "every document of this type, whatever its
            // value"; a figure means "at or above this amount".
            $table->decimal('threshold_amount', 14, 2)->nullable();
            $table->integer('priority')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('authority_id')->references('id')->on('approval_authorities');
            $table->index(['entity_type', 'is_active']);
        });

        Schema::create('approval_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('entity_type', 40);
            $table->uuid('entity_id');
            $table->uuid('rule_id');
            $table->uuid('authority_id');
            // pending | approved | rejected
            $table->string('status', 20)->default('pending');
            $table->uuid('requested_by_user_id');
            $table->timestampTz('requested_at')->useCurrent();
            $table->timestampTz('resolved_at')->nullable();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('rule_id')->references('id')->on('approval_rules');
            $table->foreign('authority_id')->references('id')->on('approval_authorities');
            $table->foreign('requested_by_user_id')->references('id')->on('users');
            $table->index(['company_id', 'entity_type', 'entity_id']);
            $table->index(['authority_id', 'status']);
        });

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id');
            $table->uuid('user_id');
            // approved | rejected
            $table->string('decision', 20);
            $table->text('comment')->nullable();
            $table->timestampTz('decided_at')->useCurrent();

            $table->foreign('request_id')->references('id')->on('approval_requests');
            $table->foreign('user_id')->references('id')->on('users');
            // One decision per approver per request.
            $table->unique(['request_id', 'user_id'], 'uq_approval_decision');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_requests');
        Schema::dropIfExists('approval_rules');
        Schema::dropIfExists('approval_authority_members');
        Schema::dropIfExists('approval_authorities');
    }
};
