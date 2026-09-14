<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/quotations.py's Quotation and
// QuotationLine exactly -- see that file's docstring for the
// confirmed 2026-09-10 accept -> auto-Contract splitting rule
// (hourly lines -> one Service Support contract, every other line ->
// one Annual contract).
//
// `reference_code_id` has no FK constraint yet, same reason as
// products.default_reference_code_id (see
// 2026_09_15_000100_create_products_table.php): it references
// backend/app/models/reference_codes.py's ReferenceCode table, which
// is not converted yet.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('quotation_number', 50);
            $table->uuid('customer_id');
            $table->date('quotation_date');
            $table->date('valid_until')->nullable();
            $table->string('status', 20)->default('draft'); // draft|sent|accepted|rejected|expired
            $table->text('notes')->nullable();

            // Net of GST -- see App\Services\QuotationService::recomputeTotals,
            // same single-rate-per-document pattern as Invoice.
            $table->decimal('amount_sgd', 12, 2)->default(0);
            $table->string('tax_code', 10)->default('SR');
            $table->decimal('gst_rate', 5, 2)->default(0);
            $table->decimal('gst_amount_sgd', 12, 2)->default(0);
            $table->decimal('total_amount_sgd', 12, 2)->default(0);

            // Set only if Accept auto-converted this quotation. A
            // quotation mixing hourly and non-hourly lines converts to
            // TWO separate contracts, never one blending both --
            // converted_contract_id is the SERVICE_SUPPORT contract
            // (from "Hours" lines), converted_annual_contract_id is
            // the ANNUAL contract (from every other line). Either or
            // both may be null depending on what lines the quotation
            // actually had.
            $table->uuid('converted_contract_id')->nullable();
            $table->uuid('converted_annual_contract_id')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('customer_id')->references('id')->on('company_individuals');
            $table->foreign('converted_contract_id')->references('id')->on('contracts');
            $table->foreign('converted_annual_contract_id')->references('id')->on('contracts');
            $table->foreign('created_by_user_id')->references('id')->on('users');
            $table->index('quotation_number');
        });

        Schema::create('quotation_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('quotation_id');
            // Optional: a line can still be free text if it doesn't
            // match a catalog item, but picking one pre-fills
            // description/price/UoM.
            $table->uuid('product_id')->nullable();
            // Stored on the line so the quotation text stays exactly
            // as sent even if the catalog item is later renamed or
            // deactivated.
            $table->string('description');
            $table->string('unit_of_measure', 50)->nullable();
            $table->decimal('quantity', 12, 2)->default(1);
            $table->decimal('unit_price_sgd', 12, 2)->default(0);
            $table->decimal('line_total_sgd', 12, 2)->default(0);
            // Reference Monitor (2026-09-12): which GL sub-code this
            // line was for -- auto-filled from the chosen product's
            // default_reference_code_id, but always overridable per
            // line. No FK yet -- see class docblock above.
            $table->uuid('reference_code_id')->nullable();
            // Costing (2026-09-12): the product cost behind this line,
            // so a later GP report can compare it against
            // unit_price_sgd/line_total_sgd. Auto-filled from the
            // chosen product's Product.cost_sgd, but always an open,
            // overridable field.
            $table->decimal('cost_sgd', 12, 2)->nullable();

            $table->foreign('quotation_id')->references('id')->on('quotations');
            $table->foreign('product_id')->references('id')->on('products');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotation_lines');
        Schema::dropIfExists('quotations');
    }
};
