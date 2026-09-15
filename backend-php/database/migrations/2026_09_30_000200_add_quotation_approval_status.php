<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quotation status model settled (Dennis, 2026-09-15: "Quotation
 * status to clarify"), closing the Sales Dashboard's two
 * always-not-available tiles.
 *
 *   draft -> pending_approval -> approved -> sent -> accepted
 *                    |                        |         rejected
 *                    +-- (sent back) -> draft +-- expired
 *
 * BILL-006 (confirmed): the Sales Manager approves every quotation
 * before it goes to the customer, with no value threshold. So
 * "pending approval" is a real state between draft and approved, and
 * "pending confirmation by client" is simply sent-not-yet-accepted --
 * the two figures the dashboard has been unable to count until now.
 *
 * The who/when of each step is kept on the row for the print form and
 * the list, on top of the audit trail's own record of it. The status
 * column itself is a plain string, so no enum change is needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->timestampTz('submitted_at')->nullable();
            $table->uuid('submitted_by_user_id')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestampTz('sent_at')->nullable();
            // Why it came back from approval, shown to whoever picks
            // the draft up again. Cleared on the next submit.
            $table->text('returned_reason')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropColumn([
                'submitted_at', 'submitted_by_user_id', 'approved_at', 'approved_by_user_id',
                'sent_at', 'returned_reason',
            ]);
        });
    }
};
