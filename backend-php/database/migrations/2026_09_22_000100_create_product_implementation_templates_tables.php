<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NEW FEATURE (not a Python->PHP conversion -- backend/ has no
// equivalent, this is new business scope built directly in
// backend-php per Dennis's request, see docs/backlog.md and
// docs/planned-work.md): "Product - To add in Job Implementation
// Template".
//
// A Job Implementation Template is a reusable, ordered task checklist
// attached to a Product. Selecting that Product on a Job Order copies
// the checklist onto the Job Order (see
// 2026_09_22_000200_create_job_order_products_and_implementation_tasks.php)
// -- the same "copy a template onto the document at creation time"
// pattern already used by PROJECT-type Job Orders' 5-milestone
// template (App\Models\ProjectMilestone).
//
// One template per product (product_id is unique) -- Dennis's request
// framed this as "Job Implementation Template" (singular) per
// product, not a library of named templates a product picks from.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_implementation_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('product_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('product_id')->references('id')->on('products');
            $table->unique('product_id', 'uq_product_implementation_template_product');
        });

        Schema::create('product_implementation_template_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('template_id');
            $table->string('task_name', 200);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('template_id')->references('id')->on('product_implementation_templates');
            $table->index('template_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_implementation_template_tasks');
        Schema::dropIfExists('product_implementation_templates');
    }
};
