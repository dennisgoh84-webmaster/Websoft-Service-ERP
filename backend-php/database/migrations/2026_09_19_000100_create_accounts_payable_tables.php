<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/payables.py's PurchaseOrder and
// SupplierInvoice. NOT included here yet: SupplierPayment /
// SupplierPaymentAllocation -- Python's own POST /payments creates a
// SupplierPayment and, in the SAME transaction, immediately posts it
// to the General Ledger (Dr AP / Cr bank); there is no "recorded but
// not posted" state for a Payment Voucher, unlike an Invoice or a
// SupplierInvoice. Since the GL posting + Bank module (`accounts`,
// `journal_entries`, `bank_accounts`) doesn't exist yet, that flow
// genuinely cannot be built faithfully right now -- so it is deferred
// to the GL posting + Bank migration, not stubbed here. See
// docs/php-conversion-plan.md.
//
// SupplierInvoice.expense_account_id (FK -> accounts.id, GL posting's
// own table) is likewise deferred -- added via a later migration once
// `accounts` exists, same pattern as ExcessUsageRecord's contract_id
// FK was deferred from the Contracts migration until service_records
// existed.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('supplier_id');
            $table->string('po_number', 50);
            $table->date('order_date');
            $table->string('description', 500);
            $table->decimal('amount_sgd', 12, 2); // net
            $table->decimal('gst_amount_sgd', 12, 2)->default(0);
            $table->decimal('total_amount_sgd', 12, 2)->default(0);
            $table->string('status', 20)->default('draft'); // draft|pending_approval|approved|cancelled
            $table->uuid('approved_by_user_id')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('supplier_id')->references('id')->on('company_individuals');
            $table->foreign('approved_by_user_id')->references('id')->on('users');
            $table->index('po_number');
        });

        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('supplier_id');
            $table->uuid('purchase_order_id')->nullable();
            $table->string('bill_number', 50);
            $table->string('supplier_invoice_no', 100)->nullable();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('description', 500);
            $table->decimal('amount_sgd', 12, 2); // net
            $table->decimal('gst_amount_sgd', 12, 2)->default(0); // input tax, recoverable
            $table->decimal('total_amount_sgd', 12, 2)->default(0);
            $table->decimal('amount_paid_sgd', 12, 2)->default(0);
            $table->string('match_status', 20)->default('not_matched'); // not_matched|matched|exception
            $table->string('match_note', 500)->nullable();
            $table->string('status', 20)->default('awaiting_match'); // awaiting_match|exception|approved|partially_paid|paid
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('supplier_id')->references('id')->on('company_individuals');
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders');
            $table->index('bill_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_invoices');
        Schema::dropIfExists('purchase_orders');
    }
};
