<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/payments.py's Payment and
// PaymentAllocation -- AR-001 (manual allocation). Deferred until now
// because Payment.bank_account_id needed `bank_accounts` (GL posting
// + Bank) to exist first, same as SupplierPayment's own migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('customer_id');
            $table->string('voucher_number', 50);
            $table->date('payment_date');
            $table->decimal('amount_sgd', 12, 2);
            $table->string('method', 30)->default('bank_transfer');
            $table->string('reference', 200)->nullable();
            $table->string('notes', 500)->nullable();
            $table->uuid('bank_account_id')->nullable();
            $table->uuid('recorded_by_user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('customer_id')->references('id')->on('company_individuals');
            $table->foreign('bank_account_id')->references('id')->on('bank_accounts');
            $table->foreign('recorded_by_user_id')->references('id')->on('users');
            $table->index('voucher_number');
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('payment_id');
            $table->uuid('invoice_id');
            $table->decimal('amount_sgd', 12, 2);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('payment_id')->references('id')->on('payments');
            $table->foreign('invoice_id')->references('id')->on('invoices');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
