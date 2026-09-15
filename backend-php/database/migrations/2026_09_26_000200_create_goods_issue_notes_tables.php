<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Goods Issue Note (GIN) -- issuing stock out to a job order, a
 * customer or internal use. Requested by Dennis 2026-09-15.
 *
 * NEW, not a conversion: backend/ has no Goods Issue Note router or
 * model. The `issue` stock movement type already existed, declared
 * "for future use" and never written by anything -- this is what
 * writes it.
 *
 * A line carries no unit cost of its own, unlike a GRN (what stock cost
 * to buy) or a GRTN (what is being claimed back from a supplier). Stock
 * leaves at the item's weighted average cost, which the issue itself
 * never moves, so a cost on the line would be a second, contradictory
 * source of truth. The cost that applied is recorded on the resulting
 * stock movement, together with the running balance after it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goods_issue_notes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('gin_number', 30);
            $table->uuid('warehouse_id');
            // Who or what the stock went to -- all optional, since an
            // issue can be internal consumption with no counterparty.
            $table->uuid('customer_id')->nullable();
            $table->uuid('job_order_id')->nullable();
            $table->timestampTz('issue_date')->useCurrent();
            $table->text('reason')->nullable();
            $table->string('status', 20)->default('draft');
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('customer_id')->references('id')->on('company_individuals');
            $table->foreign('job_order_id')->references('id')->on('job_orders');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['company_id', 'gin_number']);
        });

        Schema::create('goods_issue_note_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('gin_id');
            $table->uuid('stock_item_id');
            $table->integer('quantity');
            // Filled in on CONFIRM from the item's average cost at that
            // moment, so the document keeps what it actually issued at
            // even if the average later moves. Null while draft.
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->decimal('total_cost', 14, 2)->nullable();
            $table->text('notes')->nullable();

            $table->foreign('gin_id')->references('id')->on('goods_issue_notes');
            $table->foreign('stock_item_id')->references('id')->on('stock_items');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goods_issue_note_lines');
        Schema::dropIfExists('goods_issue_notes');
    }
};
