<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/payables.py's SupplierInvoice.
// expense_account_id and SupplierPayment/SupplierPaymentAllocation --
// both deferred from the Accounts Payable migration specifically
// because they reference `accounts`/`bank_accounts`, which didn't
// exist yet (see that migration's docblock). They belong here now
// that GL posting + Bank exists, unblocking Payment Vouchers.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->uuid('expense_account_id')->nullable()->after('purchase_order_id');
            $table->foreign('expense_account_id')->references('id')->on('accounts');
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('supplier_id');
            $table->string('voucher_number', 50);
            $table->date('payment_date');
            $table->decimal('amount_sgd', 12, 2);
            $table->string('method', 30)->default('bank_transfer');
            $table->string('reference', 200)->nullable();
            $table->string('notes', 500)->nullable();
            $table->uuid('bank_account_id')->nullable();
            $table->uuid('paid_by_user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('supplier_id')->references('id')->on('company_individuals');
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts');
            $table->foreign('paid_by_user_id')->references('id')->on('users');
            $table->index('voucher_number');
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('payment_id');
            $table->uuid('supplier_invoice_id');
            $table->decimal('amount_sgd', 12, 2);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('payment_id')->references('id')->on('supplier_payments');
            $table->foreign('supplier_invoice_id')->references('id')->on('supplier_invoices');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropForeign(['expense_account_id']);
            $table->dropColumn('expense_account_id');
        });
    }
};
