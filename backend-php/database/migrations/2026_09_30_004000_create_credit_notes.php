<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credit Notes (BILL-003; open-business-decisions.md #49, 2.7 / #38:
 * "Build it now"). A credit note is against one Sales Invoice: raised,
 * then approved -- Finance or the Sales Manager within the customer's
 * credit note limit, the owner above it or when none is set -- which
 * issues it: it takes its CN number, reverses its share of the
 * invoice's revenue and GST in the General Ledger, lowers what the
 * invoice still owes, and counts in the GST Calculation for the month
 * it is issued in. A rejected or withdrawn one is kept, never deleted.
 *
 * invoices.credited_sgd is what issued credit notes have taken off the
 * invoice, kept alongside amount_paid_sgd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            // Given when issued, so issued credit notes number without gaps.
            $t->string('credit_note_number', 30)->nullable();
            $t->uuid('invoice_id');
            $t->uuid('customer_id');
            $t->text('reason');
            // Net of GST, as keyed; GST at the invoice's own rate and tax code.
            $t->decimal('amount_sgd', 12, 2);
            $t->string('tax_code', 10)->nullable();
            $t->decimal('gst_rate', 5, 2)->nullable();
            $t->decimal('gst_amount_sgd', 12, 2)->default(0);
            $t->decimal('total_amount_sgd', 12, 2);
            $t->string('status', 20)->default('pending_approval'); // pending_approval|issued|rejected|withdrawn
            $t->uuid('raised_by_user_id')->nullable();
            $t->timestampTz('raised_at')->useCurrent();
            $t->uuid('decided_by_user_id')->nullable();
            $t->timestampTz('decided_at')->nullable();
            $t->text('decision_note')->nullable();
            $t->timestampTz('issued_at')->nullable();

            $t->foreign('company_id')->references('id')->on('companies');
            $t->foreign('invoice_id')->references('id')->on('invoices');
            $t->foreign('customer_id')->references('id')->on('company_individuals');
            $t->foreign('raised_by_user_id')->references('id')->on('users');
            $t->foreign('decided_by_user_id')->references('id')->on('users');
            $t->unique(['company_id', 'credit_note_number']);
            $t->index(['company_id', 'status']);
            $t->index('invoice_id');
        });

        Schema::table('invoices', function (Blueprint $t) {
            $t->decimal('credited_sgd', 12, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $t) {
            $t->dropColumn('credited_sgd');
        });
        Schema::dropIfExists('credit_notes');
    }
};
