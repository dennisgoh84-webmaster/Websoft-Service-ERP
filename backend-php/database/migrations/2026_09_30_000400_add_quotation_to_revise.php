<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The third answer a customer can give to a sent quotation (Dennis,
 * 2026-09-15: "quotation status after sent out to Customer, the status
 * come back is Accepted / Rejected / To Revise").
 *
 *   sent -> to_revise (what they asked to change is recorded)
 *   to_revise -> Create revision: a NEW draft quotation copying the
 *   lines, linked back through revised_from_quotation_id, which goes
 *   through approval and sending again. Quotation lines are not
 *   editable once raised, so a revision is always a new document --
 *   which is also what the customer expects to receive: a new
 *   quotation number, not a silently changed one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->timestampTz('to_revise_at')->nullable();
            $table->text('revision_reason')->nullable();
            $table->uuid('revised_from_quotation_id')->nullable();
            $table->foreign('revised_from_quotation_id')->references('id')->on('quotations');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropForeign(['revised_from_quotation_id']);
            $table->dropColumn(['to_revise_at', 'revision_reason', 'revised_from_quotation_id']);
        });
    }
};
