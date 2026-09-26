<?php

use App\Services\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Dennis, 2026-09-26:
 *
 * - Supplier bills "must have GST taken care of like Sales Invoice
 *   Logic": a bill carries a tax code and the rate it was raised at, and
 *   its GST is worked out from them. Tax codes gain a kind -- supply
 *   (SR / ZR / ES / OS, as before) or purchase -- and every company that
 *   has tax codes gets the IRAS purchase codes: TX standard-rated (at the
 *   company's SR rate), ZP zero-rated, EP exempt, OP out of scope, NR
 *   from a supplier not registered for GST. An existing bill that charged
 *   GST is TX at the rate it charged; one that charged none is left
 *   without a code (the GST Calculation reads it as before).
 * - A GST Calculation is marked submitted to IRAS -- by whom, when -- and
 *   after that the month is locked.
 */
return new class extends Migration
{
    private const PURCHASE_CODES = [
        ['TX', 'Standard-rated purchase', null],
        ['ZP', 'Zero-rated purchase', '0.00'],
        ['EP', 'Exempt purchase', '0.00'],
        ['OP', 'Out-of-scope purchase', '0.00'],
        ['NR', 'Purchase from a supplier not registered for GST', '0.00'],
    ];

    public function up(): void
    {
        Schema::table('tax_codes', function (Blueprint $table) {
            $table->string('kind', 10)->default('supply'); // supply|purchase
        });
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->string('tax_code', 10)->nullable();
            $table->decimal('gst_rate', 5, 2)->nullable();
        });
        Schema::table('gst_returns', function (Blueprint $table) {
            $table->uuid('submitted_by_user_id')->nullable();
            $table->timestampTz('submitted_at')->nullable();
            $table->foreign('submitted_by_user_id')->references('id')->on('users');
        });

        $companies = DB::table('tax_codes')->select('company_id')->distinct()->pluck('company_id');
        foreach ($companies as $companyId) {
            $sr = DB::table('tax_codes')->where('company_id', $companyId)->where('code', 'SR')->value('rate_percent') ?? '9.00';
            foreach (self::PURCHASE_CODES as [$code, $name, $rate]) {
                if (DB::table('tax_codes')->where('company_id', $companyId)->where('code', $code)->exists()) {
                    continue;
                }
                $id = (string) Str::uuid();
                DB::table('tax_codes')->insert([
                    'id' => $id, 'company_id' => $companyId, 'code' => $code, 'name' => $name,
                    'rate_percent' => $rate ?? $sr, 'is_active' => true, 'kind' => 'purchase',
                ]);
                Audit::record('tax_code', $id, 'created', null, actorName: 'System (migration 2026_09_30_003200)', companyId: $companyId,
                    details: "{$code} {$name} @ ".($rate ?? $sr).'%');
            }
        }

        DB::statement("UPDATE supplier_invoices SET tax_code = 'TX', gst_rate = round(gst_amount_sgd * 100 / amount_sgd, 2)
                       WHERE tax_code IS NULL AND gst_amount_sgd > 0 AND amount_sgd > 0");
    }

    public function down(): void
    {
        Schema::table('gst_returns', function (Blueprint $table) {
            $table->dropForeign(['submitted_by_user_id']);
            $table->dropColumn(['submitted_by_user_id', 'submitted_at']);
        });
        Schema::table('supplier_invoices', function (Blueprint $table) {
            $table->dropColumn(['tax_code', 'gst_rate']);
        });
        DB::table('tax_codes')->where('kind', 'purchase')->whereIn('code', array_column(self::PURCHASE_CODES, 0))->delete();
        Schema::table('tax_codes', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
