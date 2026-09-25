<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Module Control tidy-up (Dennis, 2026-09-26):
 *
 * - Purchasing, Projects and Hardware Management are removed. Purchasing
 *   gated nothing -- purchase orders have always been Accounts Payable's
 *   -- and the other two never had application code.
 * - Commission Management becomes a real module: the commission report,
 *   its rate and Commission Payouts move under it from Accounting
 *   Reports. Every company and group keeps exactly the access it had,
 *   copied from Accounting Reports, so nobody gains or loses a screen
 *   the day this runs.
 */
return new class extends Migration
{
    private const REMOVED = [
        'purchasing' => 'Purchasing',
        'projects' => 'Projects',
        'hardware_management' => 'Hardware Management',
    ];

    public function up(): void
    {
        $keys = array_keys(self::REMOVED);
        DB::table('group_module_authorities')->whereIn('module_key', $keys)->delete();
        DB::table('company_modules')->whereIn('module_key', $keys)->delete();
        DB::table('modules')->whereIn('key', $keys)->delete();

        DB::table('modules')->insertOrIgnore(['key' => 'commission_management', 'name' => 'Commission Management', 'is_built' => true]);
        DB::table('modules')->where('key', 'commission_management')->update([
            'is_built' => true,
            'description' => 'Commission report, commission rate, and Commission Payouts (generate / approve / pay / clawback).',
        ]);

        foreach (DB::table('company_modules')->where('module_key', 'accounting_reports')->get() as $row) {
            $existing = DB::table('company_modules')->where('company_id', $row->company_id)->where('module_key', 'commission_management');
            if ($existing->exists()) {
                $existing->update(['enabled' => $row->enabled, 'enabled_at' => $row->enabled_at]);
            } else {
                DB::table('company_modules')->insert([
                    'id' => (string) Str::uuid(),
                    'company_id' => $row->company_id,
                    'module_key' => 'commission_management',
                    'enabled' => $row->enabled,
                    'license_type' => $row->license_type,
                    'enabled_at' => $row->enabled_at,
                ]);
            }
        }

        foreach (DB::table('group_module_authorities')->where('module_key', 'accounting_reports')->get() as $row) {
            $existing = DB::table('group_module_authorities')->where('group_id', $row->group_id)->where('module_key', 'commission_management');
            if ($existing->exists()) {
                $existing->update(['access_level' => $row->access_level]);
            } else {
                DB::table('group_module_authorities')->insert([
                    'id' => (string) Str::uuid(),
                    'group_id' => $row->group_id,
                    'module_key' => 'commission_management',
                    'access_level' => $row->access_level,
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('modules')->where('key', 'commission_management')->update(['is_built' => false]);

        foreach (self::REMOVED as $key => $name) {
            DB::table('modules')->insertOrIgnore(['key' => $key, 'name' => $name, 'is_built' => $key === 'purchasing']);
        }
    }
};
