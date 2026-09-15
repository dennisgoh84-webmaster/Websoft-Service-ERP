<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A system-generated company code (Dennis, 2026-09-15: "Company setup
 * to display system generated company code").
 *
 * C001, C002, ... in creation order: assigned by App\Models\Company on
 * create, never typed in and never changed, so it can be quoted as a
 * stable short identifier for the entity (document numbering, exports,
 * Central Command's client registry) where a UUID is unwieldy and a
 * name can be edited. Existing companies are numbered here in the
 * order they were created, so the first company on any install is C001.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->string('code', 10)->nullable();
        });

        $n = 0;
        foreach (DB::table('companies')->orderBy('created_at')->orderBy('id')->get(['id']) as $row) {
            DB::table('companies')->where('id', $row->id)->update(['code' => sprintf('C%03d', ++$n)]);
        }

        Schema::table('companies', function (Blueprint $table) {
            $table->unique('code');
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropUnique(['code']);
            $table->dropColumn('code');
        });
    }
};
