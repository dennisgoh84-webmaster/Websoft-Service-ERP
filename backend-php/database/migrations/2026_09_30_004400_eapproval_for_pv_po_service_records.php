<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Backlog 2, eApproval (Dennis, 2026-09-26, decision page): Payment
 * Vouchers, Purchase Orders and Service Records move onto the eApproval
 * framework.
 *
 * - approval_requests.summary: what the approver is asked about ("PO
 *   PO-2026-0007, SGD 12,000.00 to ACME"), for the Approval Center and
 *   the email sent as each item arrives.
 * - A PO the eApproval approvers reject is cancelled with their reason
 *   (purchase_orders.cancel_reason / cancelled_at / cancelled_by_user_id).
 * - Service Records can be rejected with a reason
 *   (service_records.rejected_reason / rejected_at / rejected_by_user_id).
 * - "Approvers are an authority with Nico and Cherish (any one)": each
 *   company gets a "Service Record Approval" authority (any one) holding
 *   the users who approve Service Records today (the Service Lead and
 *   Sales Manager roles -- Nico and Cherish), with a rule for every
 *   Service Record. Each one created is written to Event Logs. Members
 *   can then be changed under eApproval Master like any authority.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->string('summary', 300)->nullable();
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->text('cancel_reason')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by_user_id')->nullable();
            $table->foreign('cancelled_by_user_id')->references('id')->on('users');
        });
        Schema::table('service_records', function (Blueprint $table) {
            $table->text('rejected_reason')->nullable();
            $table->timestampTz('rejected_at')->nullable();
            $table->uuid('rejected_by_user_id')->nullable();
            $table->foreign('rejected_by_user_id')->references('id')->on('users');
        });

        $now = Carbon::now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            $approvers = DB::table('users')->where('company_id', $companyId)->where('is_active', true)
                ->whereIn('role', ['service_lead', 'sales_manager'])->pluck('id');
            $exists = DB::table('approval_authorities')->where('company_id', $companyId)->where('name', 'Service Record Approval')->exists();
            if ($approvers->isEmpty() || $exists) {
                continue;
            }
            $authorityId = (string) Str::uuid();
            DB::table('approval_authorities')->insert([
                'id' => $authorityId, 'company_id' => $companyId, 'name' => 'Service Record Approval',
                'description' => 'Approves Service Records and keys in the hours to deduct -- any one of them.',
                'mode' => 'any_one', 'is_active' => true, 'created_at' => $now,
            ]);
            foreach ($approvers as $userId) {
                DB::table('approval_authority_members')->insert([
                    'id' => (string) Str::uuid(), 'authority_id' => $authorityId, 'user_id' => $userId, 'added_at' => $now,
                ]);
            }
            DB::table('approval_rules')->insert([
                'id' => (string) Str::uuid(), 'authority_id' => $authorityId, 'entity_type' => 'service_record',
                'threshold_amount' => null, 'priority' => 0, 'is_active' => true, 'created_at' => $now,
            ]);
            DB::table('audit_log_entries')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId, 'entity_type' => 'approval_authority',
                'entity_id' => $authorityId, 'action' => 'created', 'actor_name' => 'System (migration)',
                'details' => 'Service Record Approval authority set up from the users who approve Service Records today ('.
                    $approvers->count().' member(s), any one) -- Backlog 2, 2026-09-26',
                'at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('service_records', function (Blueprint $table) {
            $table->dropForeign(['rejected_by_user_id']);
            $table->dropColumn(['rejected_reason', 'rejected_at', 'rejected_by_user_id']);
        });
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by_user_id']);
            $table->dropColumn(['cancel_reason', 'cancelled_at', 'cancelled_by_user_id']);
        });
        Schema::table('approval_requests', function (Blueprint $table) {
            $table->dropColumn('summary');
        });
    }
};
