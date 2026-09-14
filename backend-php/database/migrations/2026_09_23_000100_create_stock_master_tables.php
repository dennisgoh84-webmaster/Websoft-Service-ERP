<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Stock / Inventory master files + the stock ledger they feed.
// Mirrors backend/app/models/inventory.py exactly (same table names,
// same snake_case column names, same nullability and string lengths) --
// see that file's docstring for the two confirmed rules this module
// exists to enforce (INV-001 stock adjustment approval, INV-002
// weighted average cost) and the six Module Control keys that gate it.
//
// Split from the movement documents (GRN/GTN/GRTN/Stock Adjustment,
// see 2026_09_23_000200_...) only so the conversion lands in reviewable
// stages; the Python module is one file.
//
// Precision note: `avg_cost`/`unit_cost` are Numeric(14, 4) in Python,
// NOT the Numeric(12, 2) used for customer-facing money elsewhere in
// this system -- a stock unit cost carries 4dp so a weighted average
// over many receipts doesn't drift. Copied exactly rather than
// normalised to 2dp; see App\Support\Money::quantize(4).
return new class extends Migration
{
    public function up(): void
    {
        // ── Stock Setup Master Files ────────────────────────────────
        Schema::create('stock_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 30);
            $table->string('name', 200);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->index(['company_id', 'code']);
        });

        Schema::create('stock_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 30);
            $table->string('name', 200);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->index(['company_id', 'code']);
        });

        Schema::create('stock_brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('name', 200);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->index(['company_id', 'name']);
        });

        // A model belongs to a brand, and only to a brand -- its
        // company is the brand's company. Matches the Python model,
        // which deliberately has no company_id of its own (the router
        // scopes every model query through its brand instead).
        Schema::create('stock_models', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('brand_id');
            $table->string('name', 200);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('brand_id')->references('id')->on('stock_brands');
        });

        Schema::create('stock_usages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 30);
            $table->string('name', 200);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->index(['company_id', 'code']);
        });

        // ── Warehouse / Location ────────────────────────────────────
        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 20);
            $table->string('name', 200);
            $table->text('address')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->index(['company_id', 'code']);
        });

        // ── Stock Item ──────────────────────────────────────────────
        // An inventory item tracked by quantity. `product_id` is the
        // OPTIONAL link to the Product/Service Catalog for items that
        // are also sold; an internal consumable has none.
        //
        // BOUNDARY (deliberately preserved, not extended): Product's
        // own "Is Stock" flag does NOT link a Product to a StockItem
        // here. docs/backlog.md and docs/planned-work.md #5 record that
        // the full Product -> Stock Master link-up is deferred pending
        // the separate Websoft Stock Distribution ERP project. The
        // Python schema stops at this one nullable FK and so does this.
        Schema::create('stock_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 50);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->string('category', 100)->nullable();
            $table->string('unit_of_measure', 30)->default('PCS');
            $table->uuid('product_id')->nullable();
            // When total stock across all warehouses drops to or below
            // this, the reorder report flags the item.
            $table->integer('reorder_level')->default(0);

            // ── Extended fields (2026-09-13) ────────────────────────
            // FK links to the setup master tables above (all nullable
            // -- optional lookups).
            $table->uuid('category_id')->nullable();
            $table->uuid('group_id')->nullable();
            $table->uuid('brand_id')->nullable();
            $table->uuid('model_id')->nullable();
            $table->uuid('usage_id')->nullable();
            $table->string('barcode', 100)->nullable();
            $table->string('part_number', 100)->nullable();
            $table->text('invoice_description')->nullable();
            $table->text('memo')->nullable();
            $table->text('notes')->nullable();
            // Dimensions stored as free text (e.g. "300 x 200 x 100
            // mm, 2.5 kg") -- no structured unit was ever confirmed.
            $table->string('dimensions', 255)->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('category_id')->references('id')->on('stock_categories');
            $table->foreign('group_id')->references('id')->on('stock_groups');
            $table->foreign('brand_id')->references('id')->on('stock_brands');
            $table->foreign('model_id')->references('id')->on('stock_models');
            $table->foreign('usage_id')->references('id')->on('stock_usages');
            $table->index(['company_id', 'code']);
        });

        Schema::create('stock_item_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('stock_item_id');
            // The name the user uploaded it under, kept for download.
            $table->string('filename', 500);
            // What it is actually called on disk (the attachment's own
            // uuid + extension) -- never the user-supplied name, so a
            // crafted filename can't escape the upload directory.
            $table->string('stored_filename', 500);
            $table->string('content_type', 100)->nullable();
            $table->integer('file_size')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('stock_item_id')->references('id')->on('stock_items');
        });

        // ── Stock Level (per item per warehouse) ────────────────────
        // Current quantity and INV-002 weighted average cost of an item
        // at one warehouse. Only ever written by App\Services\InventoryService.
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('stock_item_id');
            $table->uuid('warehouse_id');
            $table->integer('quantity')->default(0);
            // INV-002: weighted average cost per unit at this location.
            $table->decimal('avg_cost', 14, 4)->default(0);
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('stock_item_id')->references('id')->on('stock_items');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->index(['company_id', 'stock_item_id', 'warehouse_id']);
        });

        // ── Stock Movement (journal of every qty change) ────────────
        // An immutable record of a stock quantity change. Every
        // GRN/GTN/GRTN/Adjustment line writes one (two for a transfer:
        // a transfer_out at the source and a transfer_in-equivalent
        // receive at the destination). Never updated, never deleted --
        // this is the stock module's audit-grade history, per
        // CLAUDE.md's "never permanently delete" rule.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('stock_item_id');
            $table->uuid('warehouse_id');
            // receive | transfer_out | transfer_in | return_out |
            // adjustment | issue -- see App\Models\StockMovement.
            $table->string('movement_type', 20);
            $table->integer('quantity'); // +ve = in, -ve = out
            $table->decimal('unit_cost', 14, 4)->default(0);
            $table->decimal('total_cost', 14, 2)->default(0);
            // Reference to the source document: grn / gtn / grtn / adj.
            $table->string('reference_type', 30)->nullable();
            $table->uuid('reference_id')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('stock_item_id')->references('id')->on('stock_items');
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('created_by')->references('id')->on('users');
            $table->index(['company_id', 'stock_item_id']);
            $table->index(['company_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('stock_item_attachments');
        Schema::dropIfExists('stock_items');
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('stock_usages');
        Schema::dropIfExists('stock_models');
        Schema::dropIfExists('stock_brands');
        Schema::dropIfExists('stock_groups');
        Schema::dropIfExists('stock_categories');
    }
};
