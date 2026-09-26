<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Credit Note follow-ups and bank interest GST (Dennis, 2026-09-26,
 * decision page; see App\Services\CreditNotes):
 *
 * - credit_note_lines: a credit note can credit whole lines or part
 *   quantities of its invoice's lines, each with "goods returned" and
 *   the warehouse they go back into, at the cost they left at.
 * - credit_note_applications: where each credit note's money went -- its
 *   own invoice, another invoice of the customer (credit on account set
 *   against it), or a refund Payment Voucher. An invoice's credited
 *   amount is now what was applied to it. Every credit note already
 *   issued is recorded as applied in full to its own invoice (each one
 *   written to Event Logs), which is what it did.
 * - supplier_payments.refund_credit_note_id: a PV refunding a customer's
 *   credit (posts Dr AR, not AP).
 * - payments.tax_code: an Other receipt such as bank interest can be
 *   marked exempt (ES) and then counts in the GST Calculation's exempt
 *   supplies box.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_note_lines', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('credit_note_id');
            $t->uuid('invoice_line_id');
            $t->integer('line_no')->default(1);
            $t->string('description', 500)->nullable();
            $t->integer('quantity');
            $t->decimal('amount_fx', 14, 2);
            $t->decimal('amount_sgd', 14, 2);
            $t->boolean('return_to_stock')->default(false);
            $t->uuid('stock_item_id')->nullable();
            $t->uuid('warehouse_id')->nullable();
            $t->decimal('unit_cost_sgd', 14, 4)->nullable();
            $t->timestampTz('created_at')->useCurrent();
            $t->foreign('company_id')->references('id')->on('companies');
            $t->foreign('credit_note_id')->references('id')->on('credit_notes');
            $t->foreign('invoice_line_id')->references('id')->on('invoice_lines');
            $t->index('credit_note_id');
            $t->index('invoice_line_id');
        });

        Schema::create('credit_note_applications', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('company_id');
            $t->uuid('credit_note_id');
            $t->string('kind', 20); // invoice|refund
            $t->uuid('invoice_id')->nullable();
            $t->uuid('supplier_payment_id')->nullable();
            $t->decimal('amount_fx', 14, 2);
            // What it cleared off the invoice (or paid out), in SGD ...
            $t->decimal('amount_sgd', 14, 2);
            // ... and what it used of the credit note, in SGD at the note's rate.
            $t->decimal('note_amount_sgd', 14, 2);
            $t->uuid('applied_by_user_id')->nullable();
            $t->timestampTz('applied_at')->useCurrent();
            $t->foreign('company_id')->references('id')->on('companies');
            $t->foreign('credit_note_id')->references('id')->on('credit_notes');
            $t->foreign('invoice_id')->references('id')->on('invoices');
            $t->foreign('supplier_payment_id')->references('id')->on('supplier_payments');
            $t->index('credit_note_id');
            $t->index('invoice_id');
        });

        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->uuid('refund_credit_note_id')->nullable();
            $t->foreign('refund_credit_note_id')->references('id')->on('credit_notes');
        });
        Schema::table('payments', function (Blueprint $t) {
            $t->string('tax_code', 10)->nullable();
        });

        $now = Carbon::now();
        foreach (DB::table('credit_notes')->where('status', 'issued')->get() as $n) {
            $id = (string) Str::uuid();
            DB::table('credit_note_applications')->insert([
                'id' => $id, 'company_id' => $n->company_id, 'credit_note_id' => $n->id, 'kind' => 'invoice',
                'invoice_id' => $n->invoice_id, 'amount_fx' => $n->total_amount_fx ?? $n->total_amount_sgd,
                'amount_sgd' => $n->total_amount_sgd, 'note_amount_sgd' => $n->total_amount_sgd,
                'applied_by_user_id' => $n->decided_by_user_id, 'applied_at' => $n->issued_at ?? $now,
            ]);
            DB::table('audit_log_entries')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $n->company_id, 'entity_type' => 'credit_note', 'entity_id' => $n->id,
                'action' => 'applied', 'actor_name' => 'System (migration)', 'at' => $now,
                'details' => "Recorded as applied in full to its own invoice (SGD {$n->total_amount_sgd}), as issued -- credit note applications, 2026-09-26",
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $t) => $t->dropColumn('tax_code'));
        Schema::table('supplier_payments', function (Blueprint $t) {
            $t->dropForeign(['refund_credit_note_id']);
            $t->dropColumn('refund_credit_note_id');
        });
        Schema::dropIfExists('credit_note_applications');
        Schema::dropIfExists('credit_note_lines');
    }
};
