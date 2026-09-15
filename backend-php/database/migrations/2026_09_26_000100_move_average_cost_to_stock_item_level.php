<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Moves the weighted average cost from per-warehouse to per-item,
 * across all locations and branches (Dennis, 2026-09-15).
 *
 * BEFORE: `stock_levels.avg_cost` held one average per (item,
 * warehouse), so the same item could carry a different unit cost at
 * MAIN than at BR01, and a transfer had to carry the source
 * warehouse's cost across to avoid revaluing.
 *
 * AFTER: one average per (company, item), held on `stock_items`.
 * `stock_levels` keeps quantity only. A transfer is now cost-neutral
 * by definition rather than by a special rule -- there is only one
 * cost, so moving units between locations cannot change it.
 *
 * `stock_items.cost_value` is the extended value of everything on hand
 * for that item (total quantity across all warehouses x avg_cost). It
 * is recomputed from the authoritative per-warehouse quantities on
 * every movement rather than being incremented, so it cannot drift.
 *
 * The three `*_after` columns on `stock_movements` are the running
 * balance that movement produced, so a recalculation can be tallied
 * back against history (Dennis's explicit requirement). They are
 * NULLABLE on purpose: rows written before this migration have no
 * recorded running balance, and inventing one would be a fabricated
 * audit trail. Null there means "not recorded", not "zero".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_items', function (Blueprint $table) {
            $table->decimal('avg_cost', 14, 4)->default(0);
            $table->decimal('cost_value', 14, 2)->default(0);
        });

        // An adjustment-up may now carry its own cost and re-weight the
        // average, so found stock and opening balances come in at their
        // real cost (Dennis, 2026-09-15). Nullable: leaving it unset
        // keeps the previous behaviour of a pure count correction that
        // reuses the current average and never moves the weighting.
        Schema::table('stock_adjustment_lines', function (Blueprint $table) {
            $table->decimal('unit_cost', 14, 4)->nullable();
        });

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->integer('qty_after')->nullable();
            $table->decimal('avg_cost_after', 14, 4)->nullable();
            $table->decimal('cost_value_after', 14, 2)->nullable();
        });

        // Backfill: blend each item's existing per-warehouse averages
        // into one, weighted by the quantity actually held at each
        // location -- the only derivation that preserves total value.
        $rows = DB::table('stock_levels')
            ->select('company_id', 'stock_item_id')
            ->selectRaw('SUM(quantity) AS total_qty')
            ->selectRaw('SUM(quantity * avg_cost) AS total_value')
            ->groupBy('company_id', 'stock_item_id')
            ->get();

        foreach ($rows as $row) {
            $qty = (int) $row->total_qty;
            $avg = $qty > 0 ? round(((float) $row->total_value) / $qty, 4) : 0;
            DB::table('stock_items')
                ->where('id', $row->stock_item_id)
                ->where('company_id', $row->company_id)
                ->update([
                    'avg_cost' => $avg,
                    'cost_value' => round($avg * $qty, 2),
                ]);
        }

        Schema::table('stock_levels', function (Blueprint $table) {
            $table->dropColumn('avg_cost');
        });
    }

    public function down(): void
    {
        Schema::table('stock_levels', function (Blueprint $table) {
            $table->decimal('avg_cost', 14, 4)->default(0);
        });

        // Push the item-level average back down to every location it
        // holds stock at -- the inverse blend, as close as this can get.
        $items = DB::table('stock_items')->select('id', 'avg_cost')->get();
        foreach ($items as $item) {
            DB::table('stock_levels')->where('stock_item_id', $item->id)
                ->update(['avg_cost' => $item->avg_cost]);
        }

        Schema::table('stock_movements', function (Blueprint $table) {
            $table->dropColumn(['qty_after', 'avg_cost_after', 'cost_value_after']);
        });
        Schema::table('stock_adjustment_lines', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
        Schema::table('stock_items', function (Blueprint $table) {
            $table->dropColumn(['avg_cost', 'cost_value']);
        });
    }
};
