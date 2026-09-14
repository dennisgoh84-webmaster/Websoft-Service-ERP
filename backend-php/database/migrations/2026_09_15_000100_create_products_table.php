<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/catalog.py's Product (Product/Service
// Catalog) -- see that file's docstring for field rationale and which
// Odoo-screen fields were deliberately left out of this first build.
//
// `default_reference_code_id` has no FK constraint yet: it references
// backend/app/models/reference_codes.py's ReferenceCode table, which
// is not converted yet (see docs/php-conversion-plan.md). The column
// exists now so it round-trips through the API/frontend unchanged;
// add the FK once that module lands.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('product_type', 20)->default('service'); // service|product
            $table->string('name');
            $table->string('internal_reference', 50)->nullable();
            $table->string('product_category')->nullable();
            $table->string('tags')->nullable();
            $table->decimal('sales_price_sgd', 12, 2)->default(0);
            $table->decimal('cost_sgd', 12, 2)->nullable();
            $table->string('unit_of_measure', 50)->nullable();
            $table->string('tax_code', 10)->default('SR'); // DEFAULT_TAX_CODE, backend/app/models/tax.py
            $table->uuid('default_reference_code_id')->nullable(); // no FK yet -- see class docblock
            $table->boolean('is_stock')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
