<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GST F5 workflow (Dennis, 2026-09-26, open item 4b.4): once a month's
 * accounting period is locked, "GST Calculation" sums that period's
 * documents into the Form 5 boxes and keeps the result -- the figures
 * and every document behind them. The GST Return and its supporting
 * reports read what is kept here, never the live documents, so a
 * filed month cannot drift.
 *
 * A recalculation never overwrites: it adds the next version and marks
 * the one before superseded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gst_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('accounting_period_id');
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('current'); // current|superseded
            // IRAS Form 5 boxes (SGD). Boxes 9-12 are not produced by any
            // document here yet and are kept at zero.
            foreach (range(1, 13) as $box) {
                $table->decimal("box_{$box}_sgd", 14, 2)->default(0);
            }
            $table->unsignedInteger('output_document_count')->default(0);
            $table->unsignedInteger('input_document_count')->default(0);
            $table->uuid('calculated_by_user_id')->nullable();
            $table->timestampTz('calculated_at')->useCurrent();
            $table->timestampTz('superseded_at')->nullable();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('accounting_period_id')->references('id')->on('accounting_periods');
            $table->foreign('calculated_by_user_id')->references('id')->on('users');
            $table->unique(['accounting_period_id', 'version']);
            $table->index(['company_id', 'period_start']);
        });

        Schema::create('gst_return_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('gst_return_id');
            $table->string('direction', 10); // output|input
            $table->string('document_type', 30); // invoice|supplier_invoice
            $table->uuid('document_id');
            $table->string('document_number', 50);
            $table->date('document_date');
            $table->uuid('party_id')->nullable();
            $table->string('party_name')->nullable();
            $table->string('tax_code', 20)->nullable();
            $table->string('box', 20); // 1|2|3|out_of_scope|5|no_gst
            $table->decimal('net_sgd', 14, 2);
            $table->decimal('gst_sgd', 14, 2);

            $table->foreign('gst_return_id')->references('id')->on('gst_returns');
            $table->index(['gst_return_id', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gst_return_lines');
        Schema::dropIfExists('gst_returns');
    }
};
