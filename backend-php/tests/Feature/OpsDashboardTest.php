<?php

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\JobOrder;
use App\Models\ModuleCatalog;
use App\Models\OpsTask;
use App\Models\OpsTaskCategory;
use App\Models\SoftwareTask;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers App\Http\Controllers\Api\OpsDashboardController -- converted
 * from backend/app/routers/ops_dashboard.py.
 */
class OpsDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'ops_dashboard';

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => self::MODULE], ['name' => 'Ops Dashboard', 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $this->company->id, 'module_key' => self::MODULE],
            ['enabled' => true],
        );
    }

    /**
     * The Ops Dashboard is a personal board, but it is still gated on
     * the module like everything else -- so every test user needs EDIT
     * group authority, not just an account.
     */
    private function staff(string $name, string $role = User::ROLE_SUPPORT_ENGINEER): User
    {
        $user = User::factory()->for($this->company)->create([
            'full_name' => $name, 'role' => $role,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $group = Group::factory()->for($this->company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id, 'module_key' => self::MODULE,
            'access_level' => GroupModuleAuthority::EDIT,
        ]);
        UserCompanyAccess::create([
            'user_id' => $user->id, 'company_id' => $this->company->id, 'group_id' => $group->id,
        ]);

        return $user;
    }

    private function tokenFor(User $user): string
    {
        return $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])
            ->json('access_token');
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer '.$this->tokenFor($user)];
    }

    private function category(User $owner, string $name = 'Daily'): OpsTaskCategory
    {
        return OpsTaskCategory::create([
            'company_id' => $this->company->id, 'owner_user_id' => $owner->id, 'name' => $name,
        ]);
    }

    public function test_a_staff_member_sees_their_own_board(): void
    {
        $alice = $this->staff('Alice Tan');
        $category = $this->category($alice, 'Morning checks');
        OpsTask::create([
            'company_id' => $this->company->id, 'category_id' => $category->id,
            'owner_user_id' => $alice->id, 'title' => 'Check backups',
        ]);

        $body = $this->getJson('/api/ops-dashboard', $this->headers($alice))->assertOk()->json();

        $this->assertSame($alice->id, $body['staff_id']);
        $this->assertSame('Alice Tan', $body['staff_name']);
        $this->assertFalse($body['can_view_others'], 'a support engineer is not a manager');
        $this->assertCount(1, $body['categories']);
        $this->assertSame('Check backups', $body['categories'][0]['tasks'][0]['title']);
        $this->assertSame(1, $body['total_tasks']);
    }

    public function test_a_non_manager_cannot_open_someone_elses_board(): void
    {
        $alice = $this->staff('Alice Tan');
        $bob = $this->staff('Bob Lim');

        $this->getJson("/api/ops-dashboard?staff_id={$bob->id}", $this->headers($alice))
            ->assertStatus(403)
            ->assertJsonPath('detail', "Only the owner, service lead, or sales manager can view or edit another staff member's dashboard.");
    }

    public function test_a_manager_can_open_and_edit_someone_elses_board(): void
    {
        $lead = $this->staff('Nico Lead', User::ROLE_SERVICE_LEAD);
        $alice = $this->staff('Alice Tan');

        $this->getJson("/api/ops-dashboard?staff_id={$alice->id}", $this->headers($lead))
            ->assertOk()
            ->assertJsonPath('staff_id', $alice->id)
            ->assertJsonPath('can_view_others', true);

        // And may create on their behalf.
        $this->postJson('/api/ops-dashboard/categories', [
            'name' => 'Assigned by lead', 'owner_user_id' => $alice->id,
        ], $this->headers($lead))->assertOk()->assertJsonPath('owner_user_id', $alice->id);
    }

    public function test_status_counts_group_watch_with_in_progress(): void
    {
        $alice = $this->staff('Alice Tan');
        $category = $this->category($alice);
        foreach ([
            OpsTask::STATUS_NOT_STARTED,
            OpsTask::STATUS_IN_PROGRESS,
            OpsTask::STATUS_WATCH,
            OpsTask::STATUS_BLOCKED,
            OpsTask::STATUS_DONE,
        ] as $status) {
            OpsTask::create([
                'company_id' => $this->company->id, 'category_id' => $category->id,
                'owner_user_id' => $alice->id, 'title' => "Task {$status}", 'status' => $status,
            ]);
        }

        $body = $this->getJson('/api/ops-dashboard', $this->headers($alice))->assertOk()->json();

        $this->assertSame(5, $body['total_tasks']);
        $this->assertSame(1, $body['open_count']);
        // A watched item is live work, so it counts as in progress.
        $this->assertSame(2, $body['in_progress_count']);
        $this->assertSame(1, $body['blocked_count']);
        $this->assertSame(1, $body['done_count']);
    }

    public function test_new_categories_are_appended_in_order(): void
    {
        $alice = $this->staff('Alice Tan');

        $first = $this->postJson('/api/ops-dashboard/categories', ['name' => 'First'], $this->headers($alice))
            ->assertOk()->json();
        $second = $this->postJson('/api/ops-dashboard/categories', ['name' => 'Second'], $this->headers($alice))
            ->assertOk()->json();

        $this->assertSame(0, $first['sort_order']);
        $this->assertSame(1, $second['sort_order']);
    }

    public function test_a_task_inherits_its_categorys_owner(): void
    {
        $lead = $this->staff('Nico Lead', User::ROLE_SERVICE_LEAD);
        $alice = $this->staff('Alice Tan');
        $category = $this->category($alice);

        // The manager creates it, but Alice owns it -- ownership follows
        // the category, not the caller.
        $task = $this->postJson('/api/ops-dashboard/tasks', [
            'category_id' => $category->id, 'title' => 'Chase the supplier',
        ], $this->headers($lead))->assertOk()->json();

        $this->assertSame($alice->id, $task['owner_user_id']);
    }

    public function test_the_clear_flags_are_the_only_way_to_remove_a_follow_up(): void
    {
        $alice = $this->staff('Alice Tan');
        $bob = $this->staff('Bob Lim');
        $category = $this->category($alice);
        $task = OpsTask::create([
            'company_id' => $this->company->id, 'category_id' => $category->id,
            'owner_user_id' => $alice->id, 'title' => 'Follow up',
            'follow_up_staff_id' => $bob->id, 'follow_up_date' => '2026-07-01',
        ]);

        // Omitting a field means "leave it alone", so this must NOT
        // clear the follow-up.
        $this->patchJson("/api/ops-dashboard/tasks/{$task->id}", ['title' => 'Renamed'], $this->headers($alice))
            ->assertOk()
            ->assertJsonPath('follow_up_staff_id', $bob->id)
            ->assertJsonPath('follow_up_staff_name', 'Bob Lim')
            ->assertJsonPath('follow_up_date', '2026-07-01');

        $this->patchJson("/api/ops-dashboard/tasks/{$task->id}", [
            'clear_follow_up_staff' => true, 'clear_follow_up_date' => true,
        ], $this->headers($alice))->assertOk()
            ->assertJsonPath('follow_up_staff_id', null)
            ->assertJsonPath('follow_up_date', null);
    }

    public function test_only_a_real_status_change_is_audited(): void
    {
        $alice = $this->staff('Alice Tan');
        $category = $this->category($alice);
        $task = OpsTask::create([
            'company_id' => $this->company->id, 'category_id' => $category->id,
            'owner_user_id' => $alice->id, 'title' => 'A task', 'status' => OpsTask::STATUS_NOT_STARTED,
        ]);

        // Same status as before -- nothing to record.
        $this->patchJson("/api/ops-dashboard/tasks/{$task->id}", [
            'status' => OpsTask::STATUS_NOT_STARTED, 'title' => 'Renamed',
        ], $this->headers($alice))->assertOk();
        $this->assertSame(0, AuditLogEntry::where('action', 'status_changed')->count());

        $this->patchJson("/api/ops-dashboard/tasks/{$task->id}", [
            'status' => OpsTask::STATUS_DONE,
        ], $this->headers($alice))->assertOk();
        $this->assertSame(1, AuditLogEntry::where('action', 'status_changed')->count());
    }

    public function test_archiving_hides_a_task_without_deleting_it(): void
    {
        $alice = $this->staff('Alice Tan');
        $category = $this->category($alice);
        $task = OpsTask::create([
            'company_id' => $this->company->id, 'category_id' => $category->id,
            'owner_user_id' => $alice->id, 'title' => 'Done with this',
        ]);

        $this->postJson("/api/ops-dashboard/tasks/{$task->id}/archive", [], $this->headers($alice))
            ->assertOk()->assertJsonPath('id', $task->id);

        $body = $this->getJson('/api/ops-dashboard', $this->headers($alice))->assertOk()->json();
        $this->assertSame(0, $body['total_tasks']);
        // The row survives -- archive, never delete.
        $this->assertNotNull(OpsTask::find($task->id));
        $this->assertFalse(OpsTask::find($task->id)->is_active);
    }

    public function test_the_job_order_rollup_puts_undated_work_last(): void
    {
        $alice = $this->staff('Alice Tan');

        JobOrder::factory()->create([
            'company_id' => $this->company->id, 'assigned_to_user_id' => $alice->id,
            'status' => JobOrder::STATUS_OPEN, 'due_date' => null, 'subject' => 'No due date',
        ]);
        JobOrder::factory()->create([
            'company_id' => $this->company->id, 'assigned_to_user_id' => $alice->id,
            'status' => JobOrder::STATUS_ASSIGNED, 'due_date' => Carbon::today()->addDay(), 'subject' => 'Due tomorrow',
        ]);
        JobOrder::factory()->create([
            'company_id' => $this->company->id, 'assigned_to_user_id' => $alice->id,
            'status' => JobOrder::STATUS_CLOSED, 'subject' => 'Already closed',
        ]);

        $rollup = $this->getJson('/api/ops-dashboard', $this->headers($alice))->assertOk()->json('my_job_orders');

        $this->assertCount(2, $rollup, 'closed job orders are not live work');
        $this->assertSame('Due tomorrow', $rollup[0]['subject']);
        $this->assertSame('No due date', $rollup[1]['subject'], 'undated work sorts last, not first');
    }

    public function test_software_task_rollup_labels_the_role_and_lists_both_hats(): void
    {
        $alice = $this->staff('Alice Tan');

        SoftwareTask::create([
            'company_id' => $this->company->id, 'title' => 'Writing it',
            'assigned_programmer_id' => $alice->id,
        ]);
        SoftwareTask::create([
            'company_id' => $this->company->id, 'title' => 'Testing it',
            'tester_user_id' => $alice->id,
        ]);
        // Both hats on one task: it appears twice, once per role,
        // because she owes two different things on it.
        SoftwareTask::create([
            'company_id' => $this->company->id, 'title' => 'Both hats',
            'assigned_programmer_id' => $alice->id, 'tester_user_id' => $alice->id,
        ]);
        // Already tested -- not outstanding.
        SoftwareTask::create([
            'company_id' => $this->company->id, 'title' => 'Finished',
            'assigned_programmer_id' => $alice->id, 'is_tested' => true,
        ]);

        $rollup = $this->getJson('/api/ops-dashboard', $this->headers($alice))->assertOk()->json('my_software_tasks');

        $titles = array_column($rollup, 'title');
        $this->assertNotContains('Finished', $titles);
        $this->assertSame(2, count(array_filter($rollup, fn ($r) => $r['title'] === 'Both hats')));
        $roles = array_column(array_values(array_filter($rollup, fn ($r) => $r['title'] === 'Both hats')), 'role');
        $this->assertSame(['Programmer', 'Tester'], $roles);
    }

    public function test_another_companys_task_and_category_are_not_found(): void
    {
        $alice = $this->staff('Alice Tan');
        $other = Company::factory()->create();
        $theirUser = User::factory()->for($other)->create();
        $theirCategory = OpsTaskCategory::create([
            'company_id' => $other->id, 'owner_user_id' => $theirUser->id, 'name' => 'Theirs',
        ]);
        $theirTask = OpsTask::create([
            'company_id' => $other->id, 'category_id' => $theirCategory->id,
            'owner_user_id' => $theirUser->id, 'title' => 'Theirs',
        ]);

        $this->postJson('/api/ops-dashboard/tasks', [
            'category_id' => $theirCategory->id, 'title' => 'Nope',
        ], $this->headers($alice))->assertStatus(404);
        $this->patchJson("/api/ops-dashboard/tasks/{$theirTask->id}", ['title' => 'Nope'], $this->headers($alice))
            ->assertStatus(404);
    }

    public function test_an_empty_board_returns_zero_counts_not_an_error(): void
    {
        $alice = $this->staff('Alice Tan');

        $this->getJson('/api/ops-dashboard', $this->headers($alice))->assertOk()
            ->assertJsonPath('total_tasks', 0)
            ->assertJsonCount(0, 'categories')
            ->assertJsonCount(0, 'my_job_orders');
    }
}
