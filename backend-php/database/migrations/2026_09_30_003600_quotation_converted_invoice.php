<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accepting a quotation issues a Sales Invoice for its product lines
 * straight away (Dennis, 2026-09-26, decision 11.2 / #50); the quotation
 * records which invoice, beside the contracts it already records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->uuid('converted_invoice_id')->nullable();
            $table->foreign('converted_invoice_id')->references('id')->on('invoices');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropForeign(['converted_invoice_id']);
            $table->dropColumn('converted_invoice_id');
        });
    }
};
