<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dennis, 2026-09-26 (open items 4.4 and 2.7): "Purchase Order Limit and
 * Credit Note limit should be set in the company/individual file." Each
 * Company / Individual now carries its own limits, replacing the single
 * company-wide thresholds in Company Setup:
 *
 * - po_approval_limit_sgd -- a purchase order to this supplier up to the
 *   limit can be approved by anyone with authority; above it, or while
 *   no limit is set, only the owner approves (PUR-001).
 * - credit_note_approval_limit_sgd -- the same for credit notes to this
 *   customer (BILL-003); stored now, applied once credit notes exist.
 *
 * A company-wide value already set is copied onto every Company /
 * Individual of that company, so no approval changes hands the day this
 * runs; the company-wide columns then go.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('company_individuals', function (Blueprint $table) {
            $table->decimal('po_approval_limit_sgd', 12, 2)->nullable();
            $table->decimal('credit_note_approval_limit_sgd', 12, 2)->nullable();
        });

        DB::statement('UPDATE company_individuals ci SET po_approval_limit_sgd = c.po_approval_threshold_sgd
                       FROM companies c WHERE c.id = ci.company_id AND c.po_approval_threshold_sgd IS NOT NULL');
        DB::statement('UPDATE company_individuals ci SET credit_note_approval_limit_sgd = c.credit_note_approval_threshold_sgd
                       FROM companies c WHERE c.id = ci.company_id AND c.credit_note_approval_threshold_sgd IS NOT NULL');

        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['po_approval_threshold_sgd', 'credit_note_approval_threshold_sgd']);
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->decimal('credit_note_approval_threshold_sgd', 12, 2)->nullable();
            $table->decimal('po_approval_threshold_sgd', 12, 2)->nullable();
        });
        Schema::table('company_individuals', function (Blueprint $table) {
            $table->dropColumn(['po_approval_limit_sgd', 'credit_note_approval_limit_sgd']);
        });
    }
};
