<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// BUG FIX (found during the live end-to-end verification walk of the
// Stock module, see docs/php-conversion-plan.md).
//
// `stock_movements` is an ordered ledger: the movements journal and
// the Stock Item Detail screen read it newest-first, and a single user
// action routinely writes several rows inside the same second (a
// transfer writes two, a multi-line document one per line, and a whole
// receive -> transfer -> return -> adjust sequence can run inside one
// second in a test or a script).
//
// Laravel's `timestampTz()` defaults to **precision 0** -- whole
// seconds -- which is the convention every other table in this backend
// uses and which is fine for them. Here it made `ORDER BY created_at
// DESC` return same-second movements in an arbitrary order, so the
// journal could show a transfer's receipt above the receipt that
// funded it. The Python column is `DateTime(timezone=True)` with
// `server_default=func.now()`, i.e. microsecond precision, so this
// also restores the faithful behaviour rather than inventing one.
//
// Rows written inside the SAME transaction still share one timestamp
// (Postgres `CURRENT_TIMESTAMP`/`now()` is the transaction's start
// time) -- e.g. a transfer's two sides, or a multi-line document.
// That tie is identical in `backend/`, which uses `func.now()`, so it
// is left exactly as it is rather than "improved" into a difference.
//
// A separate, additive migration (not an edit to
// 2026_09_23_000100_create_stock_master_tables.php) because that one
// has already been applied -- per CLAUDE.md, database changes are
// migrations and an applied migration is never rewritten.
//
// Deliberately narrow: only this ledger column changes. The rest of
// the backend keeps the existing whole-second convention, which no
// screen depends on for ordering.
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE stock_movements ALTER COLUMN created_at TYPE timestamptz(6)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE stock_movements ALTER COLUMN created_at TYPE timestamptz(0)');
    }
};
