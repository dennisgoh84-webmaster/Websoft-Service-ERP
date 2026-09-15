<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Line items for a manually raised Sales Invoice (Dennis, 2026-09-15:
 * "Sales invoice ... when pick stock n update ... will use avg cost to
 * deduct accordingly").
 *
 * LINES ARE OPTIONAL, deliberately (confirmed 2026-09-15). Every
 * invoice this system has issued so far is header-only -- one amount,
 * auto-issued from a contract activation (BILL-001) or an excess-usage
 * decision (SRV-008) -- and those keep working untouched, with no
 * lines and no backfill. Only a manually raised invoice has lines.
 * Nothing reading `invoices` has to learn about this table to keep
 * being correct.
 *
 * A line may name a stock item, in which case issuing the invoice
 * deducts that quantity at the item's weighted average cost through
 * the same App\Services\InventoryService::deductStock() a Goods Issue
 * Note uses -- so INV-002's rules (never negative, never revalued by a
 * deduction) hold here by construction rather than by a second
 * implementation agreeing with the first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('invoice_id');
            $table->integer('line_no');
            $table->string('description');

            // What is being sold. A line can be pure text (a service,
            // a one-off charge) with neither set.
            $table->uuid('product_id')->nullable();
            $table->uuid('stock_item_id')->nullable();
            // Which warehouse the stock leaves from -- required only
            // when stock_item_id is set, enforced in the service.
            $table->uuid('warehouse_id')->nullable();

            $table->integer('quantity');
            $table->string('unit_of_measure', 20)->nullable();
            $table->decimal('unit_price_sgd', 12, 2);
            $table->decimal('line_amount_sgd', 12, 2);

            // The weighted average cost AT THE MOMENT OF ISSUE, copied
            // onto the line rather than looked up later: the item's
            // average moves with every later receipt, and an invoice's
            // gross profit must not move with it. Null on a line that
            // moves no stock.
            $table->decimal('unit_cost_sgd', 14, 4)->nullable();
            $table->decimal('cost_amount_sgd', 12, 2)->nullable();

            $table->timestampsTz();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('invoice_id')->references('id')->on('invoices')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('stock_item_id')->references('id')->on('stock_items');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->index(['invoice_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_lines');
    }
};
