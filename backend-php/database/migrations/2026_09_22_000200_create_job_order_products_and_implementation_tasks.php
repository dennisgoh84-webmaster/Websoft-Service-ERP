<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NEW FEATURE (not a Python->PHP conversion): "Job Order - To allow
// choosing of multiple Products and Template to import according to
// Product". See docs/backlog.md / docs/planned-work.md.
//
// job_order_products: many-to-many Job Order <-> Product, mirroring
// contract_products' shape (2026_09_16_000200). Selecting a product on
// a Job Order is what triggers App\Services\JobOrderImplementationTaskService
// to copy that product's Job Implementation Template tasks onto the
// Job Order (see job_order_implementation_tasks below).
//
// job_order_implementation_tasks: the copied-onto-this-Job-Order
// checklist, one row per task. Mirrors App\Models\ProjectMilestone's
// shape/spirit (ordered steps, completion tracking, completion gated
// to Sales Manager/Owner per 7.3 -- see JobOrderController's existing
// milestone-completion gate, which this reuses) but with a simpler
// two-state pending/completed lifecycle rather than ProjectMilestone's
// four states, since a Product's checklist item is "done or not", not
// a scheduled milestone with its own planned/actual date pair -- a
// deliberate, documented simplification of the mirrored pattern, not
// an oversight.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_order_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('job_order_id');
            $table->uuid('product_id');
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('job_order_id')->references('id')->on('job_orders');
            $table->foreign('product_id')->references('id')->on('products');
            $table->unique(['job_order_id', 'product_id'], 'uq_job_order_product');
        });

        Schema::create('job_order_implementation_tasks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('job_order_id');
            // Which product's template this task was copied from --
            // nullable so a manually-added task (not from any
            // template) is representable too. Kept only for traceability;
            // never used to re-derive the task's own text.
            $table->uuid('source_product_id')->nullable();
            $table->string('task_name', 200);
            $table->text('description')->nullable();
            $table->integer('sort_order')->default(0);
            $table->string('status', 20)->default('pending'); // pending|completed
            $table->uuid('completed_by_user_id')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('job_order_id')->references('id')->on('job_orders');
            $table->foreign('source_product_id')->references('id')->on('products');
            $table->foreign('completed_by_user_id')->references('id')->on('users');
            $table->index('job_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_order_implementation_tasks');
        Schema::dropIfExists('job_order_products');
    }
};
