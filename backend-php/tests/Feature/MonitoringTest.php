<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\JobOrder;
use App\Models\ModuleCatalog;
use App\Models\ServiceRecord;
use App\Models\SoftwareTask;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\Monitoring;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers App\Services\Monitoring and its controller -- converted from
 * backend/app/services/monitoring.py and routers/monitoring.py.
 */
class MonitoringTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'reporting';

    private function enableModule(Company $company, bool $enabled = true): void
    {
        ModuleCatalog::firstOrCreate(['key' => self::MODULE], ['name' => 'Reporting', 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => self::MODULE],
            ['enabled' => $enabled],
        );
    }

    private function ownerToken(Company $company): string
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $this->enableModule($company);
        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function staff(Company $company, string $name): User
    {
        return User::factory()->for($company)->create([
            'full_name' => $name,
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
    }

    private function jobOrder(Company $company, array $attrs = []): JobOrder
    {
        return JobOrder::factory()->create(array_merge(['company_id' => $company->id], $attrs));
    }

    public function test_endpoint_returns_the_full_shape_and_is_gated_on_reporting(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->getJson('/api/monitoring/support', $this->headers($token))
            ->assertOk()
            ->assertJsonStructure([
                'as_at',
                'summary' => [
                    'total_job_orders', 'total_open_job_orders', 'total_overdue_job_orders',
                    'unassigned_job_orders', 'total_pending_service_records',
                    'total_untested_software_tasks',
                ],
                'staff',
                'unassigned' => ['user_id', 'full_name', 'open_job_orders'],
            ])
            ->assertJsonPath('unassigned.full_name', 'Un-Assigned');
    }

    public function test_the_owner_is_excluded_from_the_staff_rows(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $this->staff($company, 'Alice Tan');

        // Python filters role != OWNER -- a supervisor's workload board
        // lists the staff being supervised, not the owner.
        $body = $this->getJson('/api/monitoring/support', $this->headers($token))->assertOk()->json();
        $this->assertCount(1, $body['staff']);
        $this->assertSame('Alice Tan', $body['staff'][0]['full_name']);
    }

    public function test_inactive_staff_are_excluded(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $this->staff($company, 'Alice Tan');
        User::factory()->for($company)->create([
            'full_name' => 'Gone Away', 'role' => User::ROLE_SUPPORT_ENGINEER, 'is_active' => false,
        ]);

        $body = $this->getJson('/api/monitoring/support', $this->headers($token))->assertOk()->json();
        $this->assertCount(1, $body['staff']);
    }

    public function test_unassigned_open_job_orders_land_in_the_unassigned_row(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $this->jobOrder($company, ['status' => JobOrder::STATUS_OPEN, 'assigned_to_user_id' => null]);
        $this->jobOrder($company, ['status' => JobOrder::STATUS_CLOSED, 'assigned_to_user_id' => null]);

        $body = $this->getJson('/api/monitoring/support', $this->headers($token))->assertOk()->json();
        $this->assertSame(1, $body['unassigned']['open_job_orders']);
        $this->assertSame(1, $body['summary']['unassigned_job_orders']);
        // A closed one still counts in the grand total, just not as open.
        $this->assertSame(2, $body['summary']['total_job_orders']);
        $this->assertSame(1, $body['summary']['total_open_job_orders']);
    }

    public function test_a_job_order_assigned_to_the_owner_counts_in_totals_but_in_nobodys_row(): void
    {
        // A deliberate Python behaviour: assigned_to_user_id is set, so
        // the row is NOT the Un-Assigned one, but the owner is absent
        // from the staff map, so no per-person row is incremented.
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $owner = User::where('company_id', $company->id)->where('role', User::ROLE_OWNER)->firstOrFail();
        $this->staff($company, 'Alice Tan');
        $this->jobOrder($company, ['status' => JobOrder::STATUS_OPEN, 'assigned_to_user_id' => $owner->id]);

        $body = $this->getJson('/api/monitoring/support', $this->headers($token))->assertOk()->json();
        $this->assertSame(1, $body['summary']['total_open_job_orders']);
        $this->assertSame(0, $body['unassigned']['open_job_orders']);
        $this->assertSame(0, $body['staff'][0]['open_job_orders']);
    }

    public function test_overdue_and_due_soon_bucketing_uses_the_two_day_lead(): void
    {
        $company = Company::factory()->create();
        $alice = $this->staff($company, 'Alice Tan');
        $today = Carbon::today();

        $this->jobOrder($company, ['status' => JobOrder::STATUS_OPEN, 'assigned_to_user_id' => $alice->id, 'due_date' => $today->copy()->subDay()]);
        $this->jobOrder($company, ['status' => JobOrder::STATUS_OPEN, 'assigned_to_user_id' => $alice->id, 'due_date' => $today->copy()->addDays(Monitoring::DUE_SOON_LEAD_DAYS)]);
        // Exactly one day past the lead time -- neither overdue nor due soon.
        $this->jobOrder($company, ['status' => JobOrder::STATUS_OPEN, 'assigned_to_user_id' => $alice->id, 'due_date' => $today->copy()->addDays(Monitoring::DUE_SOON_LEAD_DAYS + 1)]);
        // No due date at all -- open, but in neither bucket.
        $this->jobOrder($company, ['status' => JobOrder::STATUS_OPEN, 'assigned_to_user_id' => $alice->id, 'due_date' => null]);

        $result = Monitoring::getSupportMonitoring($company->id);
        $row = $result['staff'][0];

        $this->assertSame(4, $row['open_job_orders']);
        $this->assertSame(1, $row['overdue_job_orders']);
        $this->assertSame(1, $row['due_soon_job_orders']);
        $this->assertSame(1, $result['summary']['total_overdue_job_orders']);
    }

    public function test_closed_job_orders_are_never_bucketed_as_overdue(): void
    {
        $company = Company::factory()->create();
        $alice = $this->staff($company, 'Alice Tan');
        $this->jobOrder($company, [
            'status' => JobOrder::STATUS_CLOSED,
            'assigned_to_user_id' => $alice->id,
            'due_date' => Carbon::today()->copy()->subDays(30),
        ]);

        $result = Monitoring::getSupportMonitoring($company->id);
        $this->assertSame(0, $result['staff'][0]['overdue_job_orders']);
        $this->assertSame(0, $result['summary']['total_overdue_job_orders']);
    }

    public function test_untested_software_tasks_are_counted_per_tester_and_in_total(): void
    {
        // Was a placeholder 0 until Software Tasks was converted
        // (2026-09-15); now a real count.
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $alice = $this->staff($company, 'Alice Tan');

        SoftwareTask::create([
            'company_id' => $company->id, 'title' => 'Assigned to Alice, untested',
            'tester_user_id' => $alice->id,
        ]);
        SoftwareTask::create([
            'company_id' => $company->id, 'title' => 'No tester yet, untested',
        ]);
        SoftwareTask::create([
            'company_id' => $company->id, 'title' => 'Already tested',
            'tester_user_id' => $alice->id, 'is_tested' => true,
        ]);

        $body = $this->getJson('/api/monitoring/support', $this->headers($token))->assertOk()->json();
        // Alice gets only the one assigned to her and not yet tested.
        $this->assertSame(1, $body['staff'][0]['untested_software_tasks']);
        // The summary counts every untested task, assigned or not -- so
        // it deliberately exceeds the sum of the per-staff rows.
        $this->assertSame(2, $body['summary']['total_untested_software_tasks']);
    }

    public function test_only_this_companys_job_orders_are_counted(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        $token = $this->ownerToken($company);
        $this->jobOrder($company, ['status' => JobOrder::STATUS_OPEN, 'assigned_to_user_id' => null]);
        $this->jobOrder($other, ['status' => JobOrder::STATUS_OPEN, 'assigned_to_user_id' => null]);

        $body = $this->getJson('/api/monitoring/support', $this->headers($token))->assertOk()->json();
        $this->assertSame(1, $body['summary']['total_job_orders']);
    }

    public function test_contract_deduction_hours_roll_up_and_average_over_days_elapsed(): void
    {
        $company = Company::factory()->create();
        $alice = $this->staff($company, 'Alice Tan');
        $jobOrder = $this->jobOrder($company, ['assigned_to_user_id' => $alice->id]);

        // Use a fixed "today" mid-month so the elapsed-days denominator
        // is deterministic rather than whatever day the suite runs on.
        $asOf = Carbon::parse('2026-06-10');
        $monthStart = $asOf->copy()->startOfMonth();

        // Approved, contract deduction, earlier this month: 120 min = 2h.
        ServiceRecord::factory()->create([
            'company_id' => $company->id, 'job_order_id' => $jobOrder->id,
            'employee_user_id' => $alice->id, 'status' => ServiceRecord::STATUS_APPROVED,
            'outcome' => ServiceRecord::OUTCOME_CONTRACT_DEDUCTION, 'rounded_minutes' => 120,
            'approved_at' => $monthStart->copy()->addDays(2),
        ]);
        // Approved today: 60 min = 1h -- counts in both month and today.
        ServiceRecord::factory()->create([
            'company_id' => $company->id, 'job_order_id' => $jobOrder->id,
            'employee_user_id' => $alice->id, 'status' => ServiceRecord::STATUS_APPROVED,
            'outcome' => ServiceRecord::OUTCOME_CONTRACT_DEDUCTION, 'rounded_minutes' => 60,
            'approved_at' => $asOf->copy()->addHours(9),
        ]);
        // Approved this month but EXCESS USAGE -- counts as a record,
        // but its hours are not contract hours.
        ServiceRecord::factory()->create([
            'company_id' => $company->id, 'job_order_id' => $jobOrder->id,
            'employee_user_id' => $alice->id, 'status' => ServiceRecord::STATUS_APPROVED,
            'outcome' => ServiceRecord::OUTCOME_EXCESS_USAGE, 'rounded_minutes' => 300,
            'approved_at' => $monthStart->copy()->addDays(1),
        ]);
        // Approved LAST month -- outside the window entirely.
        ServiceRecord::factory()->create([
            'company_id' => $company->id, 'job_order_id' => $jobOrder->id,
            'employee_user_id' => $alice->id, 'status' => ServiceRecord::STATUS_APPROVED,
            'outcome' => ServiceRecord::OUTCOME_CONTRACT_DEDUCTION, 'rounded_minutes' => 600,
            'approved_at' => $monthStart->copy()->subDays(3),
        ]);
        // Submitted, not approved -- the pending queue, no hours.
        ServiceRecord::factory()->create([
            'company_id' => $company->id, 'job_order_id' => $jobOrder->id,
            'employee_user_id' => $alice->id, 'status' => ServiceRecord::STATUS_SUBMITTED,
            'outcome' => ServiceRecord::OUTCOME_PENDING, 'rounded_minutes' => 90,
            'approved_at' => null,
        ]);

        $result = Monitoring::getSupportMonitoring($company->id, $asOf);
        $row = $result['staff'][0];

        $this->assertSame(3, $row['cm_svc_records_month'], 'three approved records fall in this month');
        $this->assertSame(1, $row['cm_svc_records_today']);
        $this->assertSame(1, $row['pending_service_records']);
        $this->assertSame(1, $result['summary']['total_pending_service_records']);

        // Only the two contract-deduction records contribute hours.
        $this->assertEqualsWithDelta(3.0, $row['cm_svc_hours_month'], 0.0001);
        $this->assertEqualsWithDelta(1.0, $row['cm_svc_hours_today'], 0.0001);

        // The denominator is days elapsed so far this month (1..10 = 10),
        // NOT the number of days actually worked -- so the average reads
        // as "hours a day contributed", not "hours per active day".
        $this->assertEqualsWithDelta(3.0 / 10, $row['avg_daily_contract_hours'], 0.0001);
    }

    public function test_the_unassigned_row_never_gets_an_average(): void
    {
        // Python applies the averaging loop to the staff map only, so
        // the synthetic Un-Assigned row keeps 0.0 even though it can
        // accumulate open job orders.
        $company = Company::factory()->create();
        $this->jobOrder($company, ['status' => JobOrder::STATUS_OPEN, 'assigned_to_user_id' => null]);

        $result = Monitoring::getSupportMonitoring($company->id);
        $this->assertSame(1, $result['unassigned']['open_job_orders']);
        $this->assertEqualsWithDelta(0.0, $result['unassigned']['avg_daily_contract_hours'], 0.0001);
    }

    public function test_disabled_module_is_denied_even_with_full_group_authority(): void
    {
        $company = Company::factory()->create();
        $this->enableModule($company, false);
        ModuleCatalog::firstOrCreate(['key' => self::MODULE], ['name' => 'Reporting', 'is_built' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id, 'module_key' => self::MODULE, 'access_level' => GroupModuleAuthority::FULL,
        ]);
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $token = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])->json('access_token');

        $this->getJson('/api/monitoring/support', $this->headers($token))->assertStatus(403);
    }
}
