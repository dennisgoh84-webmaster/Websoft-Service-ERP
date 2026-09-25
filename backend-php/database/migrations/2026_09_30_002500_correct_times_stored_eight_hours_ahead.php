<?php

use App\Services\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrects the times the app stored eight hours ahead (Dennis,
 * 2026-09-26: "make all the older screens correct times instead of
 * leaving them wrong").
 *
 * Until now the app ran in Asia/Singapore while the database session
 * ran in UTC. Laravel sends a time with no offset, so every time the app
 * wrote from its own clock -- Eloquent's created_at/updated_at, now() in
 * a service, an imported date -- was read by PostgreSQL as UTC: eight
 * hours ahead. Times written by a database default, or explicitly in
 * UTC, were right. From this release the session runs in the app's time
 * zone (config/database.php), so new times are right; this moves the
 * existing wrong ones back.
 *
 * Which rows are wrong was worked out column by column from the code
 * that wrote them. A column the app always wrote is shifted outright.
 * A column written both ways is shifted only where the row can be told
 * apart: rows a Data Migration import created, or rows whose time sits
 * eight hours after the audit entry the same request wrote (audit
 * times are a database default, so they are always right).
 *
 * Deliberately not changed: journal entry dates, which are posted
 * ledger dates, and short-lived values (sign-in codes, lockouts) that
 * have long since expired. The corrections are recorded in Event Logs.
 *
 * Not reversible: shifting the rows forward again would only reinstate
 * the error.
 */
return new class extends Migration
{
    /** Columns every app writer wrote from the Singapore clock: every non-null value is eight hours ahead. */
    private const ALWAYS = [
        'accounting_periods' => ['closed_at'],
        'period_locks' => ['locked_at'],
        'journal_entries' => ['posted_at'],
        'purchase_orders' => ['approved_at'],
        'excess_usage_records' => ['decided_at'],
        'incidents' => ['closed_at'],
        'commission_payouts' => ['submitted_at', 'approved_at', 'created_at', 'updated_at'],
        'commission_settings' => ['updated_at'],
        'invoice_lines' => ['created_at', 'updated_at'],
        'goods_issue_notes' => ['issue_date', 'created_at', 'updated_at'],
        'goods_receive_notes' => ['receive_date', 'created_at', 'updated_at'],
        'goods_return_notes' => ['return_date', 'created_at', 'updated_at'],
        'goods_transfer_notes' => ['transfer_date', 'created_at', 'updated_at'],
        'stock_adjustments' => ['adjustment_date', 'created_at', 'updated_at'],
        'stock_brands' => ['created_at', 'updated_at'],
        'stock_categories' => ['created_at', 'updated_at'],
        'stock_groups' => ['created_at', 'updated_at'],
        'stock_items' => ['created_at', 'updated_at'],
        'stock_models' => ['created_at', 'updated_at'],
        'stock_usages' => ['created_at', 'updated_at'],
        'stock_levels' => ['updated_at'],
        'warehouses' => ['created_at', 'updated_at'],
        'ops_tasks' => ['created_at', 'updated_at'],
        'invoices' => ['migrated_at'],
        'payments' => ['migrated_at'],
        'migration_batches' => ['finished_at', 'imported_at', 'rolled_back_at'],
        'migration_record_map' => ['rolled_back_at'],
        'migration_mappings' => ['signed_off_at', 'updated_at'],
    ];

    /** @var array<string, int> */
    private array $counts = [];

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::ALWAYS as $table => $columns) {
                foreach ($columns as $column) {
                    $this->shift($table, $column, '');
                }
            }

            // A batch's start time is the database default until the batch
            // is run; running it overwrote it from the app clock.
            $this->shift('migration_batches', 'started_at', 'AND t.mode IS NOT NULL');

            // Imported rows: the importers wrote these from the source's
            // dates, read as Singapore midnight. Everything else written
            // to these columns was a database default or explicit UTC.
            $imported = fn (string $type) => "AND EXISTS (SELECT 1 FROM migration_record_map m WHERE m.target_id = t.id AND m.target_type = '{$type}' AND m.action = 'created')";
            $this->shift('invoices', 'issued_at', 'AND t.migrated_at IS NOT NULL');
            $this->shift('contracts', 'activated_at', $imported('contract'));
            $this->shift('job_orders', 'created_at', $imported('job_order'));
            $this->shift('job_orders', 'closed_at', $imported('job_order')
                ." AND NOT EXISTS (SELECT 1 FROM audit_log_entries a WHERE a.entity_type = 'job_order' AND a.entity_id = t.id AND a.action = 'reopened')");
            $this->shift('service_records', 'submitted_at', $imported('service_record'));
            $this->shift('service_records', 'approved_at', $imported('service_record'));

            // A voucher's Unbank voided its bank line from the app clock;
            // a void from the Bank Book itself was written in UTC and
            // carries its own audit entry.
            $this->shift('bank_transactions', 'voided_at',
                "AND NOT EXISTS (SELECT 1 FROM audit_log_entries a WHERE a.entity_type = 'bank_transaction' AND a.entity_id = t.id AND a.action = 'voided')");

            // Only edits wrote the banner settings' updated_at from the app clock.
            $this->shift('ad_banner_settings', 'updated_at', $this->auditedEightHoursEarlier("a.entity_type = 'ad_banner_settings'"));

            // Written both ways over time; the audit entry of the same
            // request tells which.
            $this->shift('user_password_history', 'set_at', $this->auditedEightHoursEarlier("a.entity_type = 'user' AND a.entity_id = t.user_id"));

            // Prospects backfilled from the old activities copied the
            // first activity's (wrong) created_at: correct them before
            // the activities themselves.
            $this->shift('prospects', 'created_at',
                "AND t.title LIKE '%(activities logged before Prospect / Leads)' AND t.created_at = (SELECT min(pa.created_at) FROM prospect_activities pa WHERE pa.prospect_id = t.id)");
            foreach (['created_at', 'updated_at'] as $column) {
                $this->shift('prospect_activities', $column, $this->auditedEightHoursEarlier("a.entity_type = 'prospect_activity' AND a.entity_id = t.id"));
            }

            // A Mobile App Time In took its work date from the UTC clock,
            // so one made before 08:00 in Singapore landed on the day
            // before. time_in itself was always right.
            $moved = DB::affectingStatement(
                "UPDATE service_records SET work_date = (time_in AT TIME ZONE 'Asia/Singapore')::date
                 WHERE time_in IS NOT NULL
                   AND work_date = (time_in AT TIME ZONE 'UTC')::date
                   AND work_date <> (time_in AT TIME ZONE 'Asia/Singapore')::date"
            );
            if ($moved > 0) {
                $this->counts['service_records.work_date'] = $moved;
            }

            if ($this->counts !== []) {
                $lines = [];
                foreach ($this->counts as $column => $n) {
                    $lines[] = "{$column}: {$n}";
                }
                Audit::record(
                    entityType: 'system',
                    entityId: '00000000-0000-0000-0000-000000000026',
                    action: 'times_corrected',
                    actorUserId: null,
                    actorName: 'System (migration 2026_09_30_002500)',
                    details: 'Times the app had stored eight hours ahead moved back to the real moment -- '.implode('; ', $lines),
                    newValue: $this->counts,
                );
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty -- see the class docblock.
    }

    /**
     * Rows whose time sits eight hours (give or take five minutes) after
     * an audit entry matching $match -- and not also beside one, which
     * would make it a right time that merely happens to fall eight hours
     * after some earlier event on the same record.
     */
    private function auditedEightHoursEarlier(string $match): string
    {
        return "AND EXISTS (SELECT 1 FROM audit_log_entries a WHERE {$match}
                  AND abs(extract(epoch FROM (t.%COL% - a.at)) - 28800) < 300)
                AND NOT EXISTS (SELECT 1 FROM audit_log_entries a WHERE {$match}
                  AND abs(extract(epoch FROM (t.%COL% - a.at))) < 300)";
    }

    private function shift(string $table, string $column, string $condition): void
    {
        $condition = str_replace('%COL%', $column, $condition);
        $n = DB::affectingStatement(
            "UPDATE {$table} AS t SET {$column} = t.{$column} - interval '8 hours' WHERE t.{$column} IS NOT NULL {$condition}"
        );
        if ($n > 0) {
            $this->counts["{$table}.{$column}"] = $n;
        }
    }
};
