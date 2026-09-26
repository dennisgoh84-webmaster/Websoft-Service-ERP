<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Each tax code says which IRAS Form 5 box it counts in (Dennis,
 * 2026-09-26, decision 47.4 "Pick a Form 5 box" and Backlog 2 "Sales and
 * purchase codes"). The built-in codes are pre-set exactly as the GST
 * Calculation has placed them so far, so no figure moves; a code with no
 * setting keeps the old rule (see App\Services\GstReturns).
 */
return new class extends Migration
{
    private const PRESET = [
        'SR' => '1', 'ZR' => '2', 'ES' => '3', 'OS' => 'out_of_scope',
        'TX' => '5', 'ZP' => '5', 'EP' => 'not_taxable', 'OP' => 'not_taxable', 'NR' => 'not_taxable',
    ];

    public function up(): void
    {
        Schema::table('tax_codes', function (Blueprint $table) {
            $table->string('form5_box', 20)->nullable();
        });
        foreach (self::PRESET as $code => $box) {
            DB::table('tax_codes')->whereRaw('upper(code) = ?', [$code])->update(['form5_box' => $box]);
        }
    }

    public function down(): void
    {
        Schema::table('tax_codes', function (Blueprint $table) {
            $table->dropColumn('form5_box');
        });
    }
};
