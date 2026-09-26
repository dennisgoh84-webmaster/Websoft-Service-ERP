<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Two more empty Module Control placeholders removed (Dennis,
 * 2026-09-26: "Proceed to remove"):
 *
 * - `inventory` never had code: everything stock-related runs under its
 *   own keys (stock_master, goods_receive_note, goods_transfer_note,
 *   goods_return_note, goods_issue_note, stock_adjustment,
 *   stock_operation_reports).
 * - `integrations` never had code either: Data Migration
 *   (`data_migration`) is what brings the ODOO / ZSOFT data in.
 */
return new class extends Migration
{
    private const REMOVED = [
        'inventory' => 'Inventory',
        'integrations' => 'Integrations (incl. Odoo migration)',
    ];

    public function up(): void
    {
        $keys = array_keys(self::REMOVED);
        DB::table('group_module_authorities')->whereIn('module_key', $keys)->delete();
        DB::table('company_modules')->whereIn('module_key', $keys)->delete();
        DB::table('modules')->whereIn('key', $keys)->delete();
    }

    public function down(): void
    {
        foreach (self::REMOVED as $key => $name) {
            DB::table('modules')->insertOrIgnore(['key' => $key, 'name' => $name, 'is_built' => false]);
        }
    }
};
