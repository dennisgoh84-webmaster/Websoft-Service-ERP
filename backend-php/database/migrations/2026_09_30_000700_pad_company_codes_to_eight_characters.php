<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Dennis's clarification (2026-09-15) of the company code form set by
 * 2026_09_30_000600: the running number is zero-padded so every code
 * is eight characters -- WEBCOPT1 stays as it is, but "Acme
 * Manufacturing" is ACMMA001 rather than ACMMA1 and "Acme" is
 * ACM00001. Re-pads every existing code in place, keeping each
 * company's number; safe to run whether or not the earlier migration
 * has been applied, since a code already in the padded form is left
 * unchanged. See App\Models\Company::formatCode().
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (DB::table('companies')->get(['id', 'code']) as $row) {
            if (preg_match('/^([A-Z]+?)0*(\d+)$/', (string) $row->code, $m)) {
                $padded = Company::formatCode($m[1], (int) $m[2]);
                if ($padded !== $row->code) {
                    DB::table('companies')->where('id', $row->id)->update(['code' => $padded]);
                }
            }
        }
    }

    public function down(): void
    {
        foreach (DB::table('companies')->get(['id', 'code']) as $row) {
            if (preg_match('/^([A-Z]+?)0*(\d+)$/', (string) $row->code, $m)) {
                DB::table('companies')->where('id', $row->id)->update(['code' => $m[1].(int) $m[2]]);
            }
        }
    }
};
