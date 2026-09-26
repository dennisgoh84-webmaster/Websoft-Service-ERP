<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bank charges, bank interest and the like go through a Receipt or a
 * Payment Voucher, never keyed straight into the Bank Book (Dennis,
 * 2026-09-26, open-business-decisions.md #49 / 31.1): "Should not allow
 * them to key direct, have to key in through receipt or payment." So a
 * receipt or payment voucher is now either for a Company / Individual
 * (as before, settling invoices or bills) or "Other" -- against a GL
 * account instead, e.g. bank interest credited to an income account,
 * bank charges debited to an expense account. It then reaches the
 * General Ledger on save and the Bank Book through the usual Bank step.
 *
 * Exactly one of the two is set on every voucher, enforced here.
 * Existing vouchers all have their Company / Individual, so nothing
 * changes for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['payments' => 'customer_id', 'supplier_payments' => 'supplier_id'] as $table => $party) {
            Schema::table($table, function (Blueprint $t) use ($party) {
                $t->uuid($party)->nullable()->change();
                $t->uuid('gl_account_id')->nullable();
                $t->foreign('gl_account_id')->references('id')->on('accounts');
            });
            DB::statement("alter table {$table} add constraint {$table}_party_or_gl_account check (({$party} is null) <> (gl_account_id is null))");
        }
    }

    public function down(): void
    {
        foreach (['payments' => 'customer_id', 'supplier_payments' => 'supplier_id'] as $table => $party) {
            DB::statement("alter table {$table} drop constraint {$table}_party_or_gl_account");
            Schema::table($table, function (Blueprint $t) {
                $t->dropForeign(['gl_account_id']);
                $t->dropColumn('gl_account_id');
            });
        }
    }
};
