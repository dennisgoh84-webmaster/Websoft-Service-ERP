<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Document numbering. Mirrors backend/app/services/numbering.py's
// DocumentSequence and DocumentNumberFormat -- see that file's
// docstring for the full rationale (locked counter row per company/
// doc-kind/year; per-company running-number customization is
// deliberately additive and never touches numbers already issued).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_sequences', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('doc_kind', 30);
            $table->integer('year');
            $table->integer('last_number')->default(0);

            $table->unique(['company_id', 'doc_kind', 'year'], 'uq_document_sequence');
        });

        Schema::create('document_number_formats', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('doc_kind', 30);
            $table->string('prefix', 10);
            $table->integer('number_length')->default(4);
            $table->boolean('include_year')->default(true);

            $table->unique(['company_id', 'doc_kind'], 'uq_document_number_format');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_number_formats');
        Schema::dropIfExists('document_sequences');
    }
};
