<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NEW FEATURE (not a Python->PHP conversion): "Service Contract - To
// be able to link to Sales Quotation upon Renewal or Expired". See
// docs/backlog.md / docs/planned-work.md.
//
// PRAGMATIC DEFAULT / KNOWN GAP (per CLAUDE.md's "never assume a
// business rule when requirements have not been provided" -- flagged
// for Dennis's confirmation, not silently assumed as decided): a real
// Sales Quotation module does not exist in backend-php yet (only in
// backend/, the Python side this work does not touch -- see
// App\Models\Contract's docblock and docs/planned-work.md for the
// full explanation). Rather than build a real foreign-key link to a
// module this backend cannot reach, this is a simple free-text
// reference field a human types the quotation number into. It is NOT
// a real linked record -- no validation that the reference actually
// exists, no join, no data pulled from it. Replace this with a real
// contract_id -> quotation_id foreign key once Quotations is
// converted to backend-php.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('quotation_reference', 50)->nullable()->after('renewed_from_contract_id');
            $table->timestampTz('quotation_reference_set_at')->nullable();
            $table->uuid('quotation_reference_set_by')->nullable();

            $table->foreign('quotation_reference_set_by')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['quotation_reference_set_by']);
            $table->dropColumn(['quotation_reference', 'quotation_reference_set_at', 'quotation_reference_set_by']);
        });
    }
};
