<?php

use App\Models\CompanyIndividual;
use App\Services\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Every existing Company / Individual's ID (the Odoo / legacy customer
 * ID) and name in FULL CAPITALS, tidied of stray spaces (Dennis,
 * 2026-09-26) -- the same rule CompanyIndividual now applies to every
 * new or edited record. Each record changed is recorded in Event Logs
 * with its old and new values.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $rows = DB::table('company_individuals')->select('id', 'company_id', 'name', 'legacy_customer_code')->orderBy('id')->get();
            foreach ($rows as $row) {
                $name = CompanyIndividual::caps($row->name);
                $code = CompanyIndividual::caps($row->legacy_customer_code);
                $code = $code === '' ? null : $code;
                if ($name === $row->name && $code === $row->legacy_customer_code) {
                    continue;
                }
                DB::table('company_individuals')->where('id', $row->id)->update(['name' => $name, 'legacy_customer_code' => $code]);
                Audit::record(
                    entityType: 'customer',
                    entityId: $row->id,
                    action: 'standardised_to_capitals',
                    actorUserId: null,
                    actorName: 'System (migration 2026_09_30_002900)',
                    companyId: $row->company_id,
                    oldValue: ['name' => $row->name, 'legacy_customer_code' => $row->legacy_customer_code],
                    newValue: ['name' => $name, 'legacy_customer_code' => $code],
                );
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: the old spellings are in Event Logs.
    }
};
