<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/tax.py's TaxCode and
// backend/app/models/billing.py's Invoice.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 10);
            $table->string('name', 100);
            $table->decimal('rate_percent', 5, 2);
            $table->boolean('is_active')->default(true);

            $table->foreign('company_id')->references('id')->on('companies');
            $table->unique(['company_id', 'code'], 'uq_tax_code');
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('customer_id');
            $table->uuid('contract_id')->nullable();
            $table->uuid('excess_usage_record_id')->nullable();
            $table->string('invoice_number', 50);
            $table->string('invoice_type', 30); // contract_annual|excess_usage
            $table->string('description', 500);
            $table->decimal('amount_sgd', 12, 2); // net, excl. GST -- see model docblock
            $table->string('tax_code', 10)->default('SR');
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('gst_amount_sgd', 12, 2)->default(0);
            $table->decimal('total_amount_sgd', 12, 2)->default(0);
            $table->decimal('cost_sgd', 12, 2)->nullable(); // GP costing snapshot; null = no known cost basis
            $table->date('due_date')->nullable();
            $table->string('status', 20)->default('outstanding'); // outstanding|partially_paid|paid|written_off
            $table->decimal('amount_paid_sgd', 12, 2)->default(0);
            $table->boolean('is_disputed')->default(false);
            $table->string('dispute_note', 500)->nullable();
            $table->timestampTz('issued_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('customer_id')->references('id')->on('company_individuals');
            $table->foreign('contract_id')->references('id')->on('contracts');
            $table->foreign('excess_usage_record_id')->references('id')->on('excess_usage_records');
            $table->index('invoice_number');
        });

        // Laravel's schema builder has no fluent CHECK-constraint
        // helper -- same pattern as the Contracts migration.
        DB::statement(
            'alter table invoices add constraint ck_invoice_amount_paid_not_negative check (amount_paid_sgd >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('tax_codes');
    }
};
