<?php

use App\Services\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Document lines keep the order they were keyed in (`line_no`; see
 * App\Models\Concerns\HasLineNumber). These six line tables have UUID
 * keys and their documents ordered lines by id, so a quotation or a
 * stock note could list its lines in a different order from the one
 * keyed -- found by the self-test on 2026-09-26 (docs/self-test.md).
 *
 * Existing lines are numbered from the best record there is of the
 * order they were keyed: their creation time where the table has one,
 * then id. Each document whose lines are numbered is recorded in Event
 * Logs, with the number given to each line.
 */
return new class extends Migration
{
    /** line table => [parent key, parent table, document kind for Event Logs, number column] */
    private const TABLES = [
        'quotation_lines' => ['quotation_id', 'quotations', 'quotation', 'quotation_number'],
        'goods_receive_note_lines' => ['grn_id', 'goods_receive_notes', 'goods_receive_note', 'grn_number'],
        'goods_transfer_note_lines' => ['gtn_id', 'goods_transfer_notes', 'goods_transfer_note', 'gtn_number'],
        'goods_return_note_lines' => ['grtn_id', 'goods_return_notes', 'goods_return_note', 'grtn_number'],
        'stock_adjustment_lines' => ['adjustment_id', 'stock_adjustments', 'stock_adjustment', 'adj_number'],
        'goods_issue_note_lines' => ['gin_id', 'goods_issue_notes', 'goods_issue_note', 'gin_number'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => [$parentKey]) {
            Schema::table($table, function (Blueprint $t) {
                $t->integer('line_no')->nullable();
            });
        }

        DB::transaction(function () {
            foreach (self::TABLES as $table => [$parentKey, $parentTable, $kind, $numberColumn]) {
                $order = Schema::hasColumn($table, 'created_at') ? 'created_at, id' : 'id';
                $lines = DB::select("select id, {$parentKey} as parent, row_number() over (partition by {$parentKey} order by {$order}) as n from {$table}");
                $byParent = [];
                foreach ($lines as $line) {
                    DB::table($table)->where('id', $line->id)->update(['line_no' => $line->n]);
                    $byParent[$line->parent][$line->id] = (int) $line->n;
                }
                $numberCol = Schema::hasColumn($parentTable, $numberColumn) ? $numberColumn : null;
                foreach ($byParent as $parentId => $numbers) {
                    $doc = DB::table($parentTable)->where('id', $parentId)->first();
                    Audit::record(
                        entityType: $kind,
                        entityId: $parentId,
                        action: 'line_order_numbered',
                        actorUserId: null,
                        actorName: 'System (migration 2026_09_30_003500)',
                        companyId: $doc->company_id ?? null,
                        details: ($numberCol && $doc ? $doc->{$numberCol}.': ' : '').count($numbers).' line(s) numbered in the order they were keyed',
                        newValue: ['line_no' => $numbers],
                    );
                }
            }
        });
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('line_no');
            });
        }
    }
};
