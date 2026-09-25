<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The time rule in CLAUDE.md's Development Rules: the app and the
 * database session run in the same time zone, and app code writes the
 * current moment with now() / Carbon::now(). Until 2026-09-26 the
 * session was UTC while the app ran in Singapore time, which stored
 * every time the app wrote eight hours ahead.
 */
class TimeZoneRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_database_session_runs_in_the_apps_time_zone(): void
    {
        $session = DB::selectOne('SHOW TIMEZONE')->TimeZone;

        $this->assertSame(config('app.timezone'), $session);
    }

    public function test_a_time_the_app_writes_is_the_real_moment(): void
    {
        $company = Company::factory()->create();
        $before = time();
        $warehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'TZ', 'name' => 'Time zone check']);

        // Read the stored instant straight from the database, as epoch
        // seconds, so no PHP-side time zone handling can hide an offset.
        $stored = (int) DB::selectOne('SELECT EXTRACT(EPOCH FROM created_at) AS e FROM warehouses WHERE id = ?', [$warehouse->id])->e;

        $this->assertEqualsWithDelta($before, $stored, 5, 'Eloquent timestamps land at the real moment, not eight hours off');
        $this->assertSame(Carbon::now()->toDateString(), $warehouse->fresh()->created_at->toDateString());
    }

    public function test_a_commit_time_from_the_upgrade_agent_keeps_its_moment(): void
    {
        config(['websoft.upgrade_agent_token' => 'tz-token']);
        $this->postJson('/api/system/upgrade-agent/heartbeat', [
            'current_sha' => 'abc', 'current_committed_at' => '2026-09-25T17:48:00+00:00',
        ], ['X-Upgrade-Agent-Token' => 'tz-token'])->assertOk();

        $stored = DB::selectOne('SELECT EXTRACT(EPOCH FROM current_committed_at) AS e FROM upgrade_agent_state')->e;
        $this->assertSame(Carbon::parse('2026-09-25T17:48:00+00:00')->getTimestamp(), (int) $stored);
    }

    public function test_the_correction_moves_back_only_the_times_that_were_written_wrong(): void
    {
        $company = Company::factory()->create();
        $realMoment = Carbon::parse('2026-09-20 10:00:00', 'Asia/Singapore');
        $storedAhead = $realMoment->copy()->addHours(8);
        $epoch = fn (string $sql, array $b = []) => (int) DB::selectOne("SELECT EXTRACT(EPOCH FROM ({$sql})) AS e", $b)->e;

        // A column the app always wrote itself: shifted.
        $warehouse = Warehouse::create(['company_id' => $company->id, 'code' => 'W1', 'name' => 'Main']);
        DB::table('warehouses')->where('id', $warehouse->id)->update(['created_at' => $storedAhead, 'updated_at' => $storedAhead]);

        // Two bank voids: one from the Bank Book (written in UTC, audited
        // as `voided`) stays; one from a voucher's Unbank is shifted.
        $account = BankAccount::factory()->for($company)->create();
        $bankLine = function (string $number, Carbon $voidedAt) use ($company, $account): string {
            $id = (string) Str::uuid();
            DB::table('bank_transactions')->insert([
                'id' => $id, 'company_id' => $company->id, 'bank_account_id' => $account->id, 'transaction_number' => $number,
                'transaction_date' => '2026-09-20', 'description' => 'x', 'is_voided' => true, 'voided_at' => $voidedAt,
            ]);

            return $id;
        };
        $manual = $bankLine('BT-1', $realMoment);
        $unbanked = $bankLine('BT-2', $storedAhead);
        DB::table('audit_log_entries')->insert(['id' => (string) Str::uuid(), 'entity_type' => 'bank_transaction', 'entity_id' => $manual, 'action' => 'voided', 'at' => $realMoment]);

        // A prospect activity: wrong if it sits eight hours after its own
        // audit entry, right if it sits beside one.
        $prospect = Prospect::factory()->create(['company_id' => $company->id]);
        $old = ProspectActivity::create(['company_id' => $company->id, 'customer_id' => $prospect->customer_id, 'prospect_id' => $prospect->id, 'activity_type' => 'call', 'subject' => 'old', 'status' => 'completed']);
        $new = ProspectActivity::create(['company_id' => $company->id, 'customer_id' => $prospect->customer_id, 'prospect_id' => $prospect->id, 'activity_type' => 'call', 'subject' => 'new', 'status' => 'completed']);
        foreach ([$old, $new] as $a) {
            DB::table('audit_log_entries')->insert(['id' => (string) Str::uuid(), 'entity_type' => 'prospect_activity', 'entity_id' => $a->id, 'action' => 'created', 'at' => $realMoment]);
        }
        DB::table('prospect_activities')->where('id', $old->id)->update(['created_at' => $storedAhead, 'updated_at' => $storedAhead]);
        DB::table('prospect_activities')->where('id', $new->id)->update(['created_at' => $realMoment, 'updated_at' => $realMoment]);

        (require database_path('migrations/2026_09_30_002500_correct_times_stored_eight_hours_ahead.php'))->up();

        $at = $realMoment->getTimestamp();
        $this->assertSame($at, $epoch('SELECT created_at FROM warehouses WHERE id = ?', [$warehouse->id]));
        $this->assertSame($at, $epoch('SELECT voided_at FROM bank_transactions WHERE id = ?', [$manual]), 'a right time is left alone');
        $this->assertSame($at, $epoch('SELECT voided_at FROM bank_transactions WHERE id = ?', [$unbanked]));
        $this->assertSame($at, $epoch('SELECT created_at FROM prospect_activities WHERE id = ?', [$old->id]));
        $this->assertSame($at, $epoch('SELECT created_at FROM prospect_activities WHERE id = ?', [$new->id]), 'a right time is left alone');
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'system', 'action' => 'times_corrected']);
    }

    public function test_app_code_never_writes_times_in_utc(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(app_path()));
        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                if (preg_match('/now\(\s*[\'"]UTC[\'"]\s*\)|->utc\(\)|setTimezone\(\s*[\'"]UTC[\'"]\s*\)/i', $line)) {
                    $offenders[] = str_replace(base_path().'/', '', $file->getPathname()).':'.($n + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'Write times with now() / Carbon::now() (CLAUDE.md, Development Rules): '.implode(', ', $offenders));
    }
}
