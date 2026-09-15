<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contract <-> Sales Quotation as a real link (Dennis, 2026-09-15:
 * "Contract renewal link to quotation and contract expiry option to
 * link/convert to quotation"), replacing the free-text
 * contracts.quotation_reference that stood in for it while Quotations
 * was unconverted (SALES-006).
 *
 *   contracts.quotation_id        the quotation this contract came
 *                                 from -- set by accepting a quotation
 *                                 (both contracts it creates point back
 *                                 at it) or linked by hand.
 *   quotations.renews_contract_id set on a quotation raised FROM an
 *                                 expiring contract as its renewal;
 *                                 accepting it renews that contract
 *                                 through ContractService::renewContract
 *                                 rather than creating a fresh one.
 *
 * quotation_reference and its set_at/set_by stay where they are: a
 * value someone typed is a record, not something to drop.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->uuid('quotation_id')->nullable();
            $table->foreign('quotation_id')->references('id')->on('quotations');
        });
        Schema::table('quotations', function (Blueprint $table) {
            $table->uuid('renews_contract_id')->nullable();
            $table->foreign('renews_contract_id')->references('id')->on('contracts');
        });
    }

    public function down(): void
    {
        Schema::table('quotations', function (Blueprint $table) {
            $table->dropForeign(['renews_contract_id']);
            $table->dropColumn('renews_contract_id');
        });
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['quotation_id']);
            $table->dropColumn('quotation_id');
        });
    }
};
