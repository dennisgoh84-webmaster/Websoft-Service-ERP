<?php

use App\Models\Company;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The company code takes the form Dennis specified (2026-09-15): three
 * letters of the first word, two of the second, two of the third, then
 * a running number from 1 -- "Webmaster Consultancy Pte Ltd" is
 * WEBCOPT1 -- replacing the C001 placeholder from earlier the same
 * day. Every existing company is recoded here from its name, in
 * creation order, numbering per prefix. See App\Models\Company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('code', 20)->change();
        });

        // Clear first so the per-prefix numbering below starts clean.
        DB::table('companies')->update(['code' => null]);

        $next = [];
        foreach (DB::table('companies')->orderBy('created_at')->orderBy('id')->get(['id', 'name']) as $row) {
            $prefix = Company::codePrefix((string) $row->name);
            $next[$prefix] = ($next[$prefix] ?? 0) + 1;
            DB::table('companies')->where('id', $row->id)->update(['code' => $prefix.$next[$prefix]]);
        }
    }

    public function down(): void
    {
        DB::table('companies')->update(['code' => null]);
        $n = 0;
        foreach (DB::table('companies')->orderBy('created_at')->orderBy('id')->get(['id']) as $row) {
            DB::table('companies')->where('id', $row->id)->update(['code' => sprintf('C%03d', ++$n)]);
        }
        Schema::table('companies', function (Blueprint $table) {
            $table->string('code', 10)->change();
        });
    }
};
