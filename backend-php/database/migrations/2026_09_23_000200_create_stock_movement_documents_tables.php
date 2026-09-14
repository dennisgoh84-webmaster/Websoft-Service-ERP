<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// The four stock movement documents. Mirrors the second half of
// backend/app/models/inventory.py exactly: Goods Receive Note (GRN),
// Goods Transfer Note (GTN), Goods Return Note (GRTN) and Stock
// Adjustment, each with its own lines table.
//
// Confirmed rules these tables exist to carry:
//   INV-001 -- a stock adjustment requires manager approval before it
//              takes effect (the status column below: draft ->
//              pending_approval -> approved | rejected).
//   INV-002 -- inventory is valued at weighted average cost, which is
//              why a GRN and a GRTN line each carry their own
//              unit_cost/total_cost; see App\Services\InventoryService.
//
// No document is ever deleted in either backend (there is no DELETE
// route), per CLAUDE.md's "never permanently delete important business
// records" -- a confirmed document is corrected by a further movement,
// not by removing it.
//
// Precision note: unit_cost is Numeric(14, 4) and total_cost
// Numeric(14, 2), copied from the Python columns rather than
// normalised to the Numeric(12, 2) used for customer-facing money.
return new class extends Migration
{
    public function up(): void
    {
        // ── Goods Receive Note (GRN) ────────────────────────────────
        Schema::create('goods_receive_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('grn_number', 30);
            $table->uuid('warehouse_id');
            // Optional link to the supplier (a CompanyIndividual
            // flagged is_supplier -- 2026-09-12: suppliers live in the
            // customer master, not a separate table).
            $table->uuid('supplier_id')->nullable();
            // Optional link to the Purchase Order being received.
            $table->uuid('purchase_order_id')->nullable();
            $table->timestampTz('receive_date')->useCurrent();
            $table->string('status', 20)->default('draft'); // draft|confirmed|cancelled
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('supplier_id')->references('id')->on('company_individuals');
            $table->foreign('purchase_order_id')->references('id')->on('purchase_orders');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['company_id', 'grn_number']);
        });

        Schema::create('goods_receive_note_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('grn_id');
            $table->uuid('stock_item_id');
            $table->integer('quantity');
            // The cost this receipt brings in -- the only new cost
            // information the weighted average (INV-002) ever gets.
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->text('notes')->nullable();

            $table->foreign('grn_id')->references('id')->on('goods_receive_notes');
            $table->foreign('stock_item_id')->references('id')->on('stock_items');
        });

        // ── Goods Transfer Note (GTN) ───────────────────────────────
        Schema::create('goods_transfer_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('gtn_number', 30);
            $table->uuid('from_warehouse_id');
            $table->uuid('to_warehouse_id');
            $table->timestampTz('transfer_date')->useCurrent();
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('from_warehouse_id')->references('id')->on('warehouses');
            $table->foreign('to_warehouse_id')->references('id')->on('warehouses');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['company_id', 'gtn_number']);
        });

        // A transfer line carries no cost: the units move at the source
        // warehouse's current weighted average cost, read at confirm
        // time. See App\Services\InventoryService::confirmGtn.
        Schema::create('goods_transfer_note_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('gtn_id');
            $table->uuid('stock_item_id');
            $table->integer('quantity');
            $table->text('notes')->nullable();

            $table->foreign('gtn_id')->references('id')->on('goods_transfer_notes');
            $table->foreign('stock_item_id')->references('id')->on('stock_items');
        });

        // ── Goods Return Note (GRTN) ────────────────────────────────
        Schema::create('goods_return_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('grtn_number', 30);
            $table->uuid('warehouse_id');
            $table->uuid('supplier_id')->nullable();
            $table->timestampTz('return_date')->useCurrent();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('supplier_id')->references('id')->on('company_individuals');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['company_id', 'grtn_number']);
        });

        // A return line records the cost claimed back from the
        // supplier. Note the stock movement it creates still leaves at
        // the warehouse's weighted average cost, NOT at this figure --
        // exactly like the Python service; the two can legitimately
        // differ and this column is the document's own record.
        Schema::create('goods_return_note_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('grtn_id');
            $table->uuid('stock_item_id');
            $table->integer('quantity');
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('total_cost', 14, 2)->default(0);
            $table->text('notes')->nullable();

            $table->foreign('grtn_id')->references('id')->on('goods_return_notes');
            $table->foreign('stock_item_id')->references('id')->on('stock_items');
        });

        // ── Stock Adjustment (INV-001) ──────────────────────────────
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('adj_number', 30);
            $table->uuid('warehouse_id');
            $table->timestampTz('adjustment_date')->useCurrent();
            $table->text('reason')->nullable();
            // INV-001: draft -> pending_approval -> approved (stock
            // changes take effect) | rejected (no stock change).
            $table->string('status', 20)->default('draft');
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('approved_by')->references('id')->on('users');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['company_id', 'adj_number']);
        });

        Schema::create('stock_adjustment_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('adjustment_id');
            $table->uuid('stock_item_id');
            $table->integer('quantity_change'); // +ve = increase, -ve = decrease
            $table->text('notes')->nullable();

            $table->foreign('adjustment_id')->references('id')->on('stock_adjustments');
            $table->foreign('stock_item_id')->references('id')->on('stock_items');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustment_lines');
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('goods_return_note_lines');
        Schema::dropIfExists('goods_return_notes');
        Schema::dropIfExists('goods_transfer_note_lines');
        Schema::dropIfExists('goods_transfer_notes');
        Schema::dropIfExists('goods_receive_note_lines');
        Schema::dropIfExists('goods_receive_notes');
    }
};
