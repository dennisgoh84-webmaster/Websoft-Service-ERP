<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\JobOrder;
use App\Models\ServiceRecord;
use App\Models\SystemMailSetting;
use App\Models\User;
use App\Services\Mailer;
use App\Services\ServiceRecordService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * SRV-019's daily 08:45 reminder (Dennis, 2026-09-26, #50): the approvers
 * are emailed the Service Records waiting more than a week for approval.
 */
class ServiceRecordReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mailer::fake();
    }

    protected function tearDown(): void
    {
        Mailer::restore();
        parent::tearDown();
    }

    private function mailbox(): void
    {
        SystemMailSetting::create([
            'purpose' => 'otp', 'host' => 'smtp.example.test', 'port' => 587, 'username' => 'erp@webmaster.example',
            'password' => 'pw', 'use_tls' => true, 'from_email' => 'erp@webmaster.example', 'from_name' => 'Websoft ERP',
        ]);
    }

    /** @return array{0: Company, 1: JobOrder, 2: User} */
    private function jobOrder(): array
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create(['name' => 'Acme']);
        $contract = Contract::factory()->for($company)->create(['customer_id' => $customer->id, 'contracted_minutes' => 600, 'status' => Contract::STATUS_ACTIVE]);
        $jo = JobOrder::factory()->for($company)->create(['customer_id' => $customer->id, 'contract_id' => $contract->id]);

        return [$company, $jo, User::factory()->for($company)->create(['full_name' => 'Engineer Ed'])];
    }

    private function submitAt(string $when, JobOrder $jo, User $employee): ServiceRecord
    {
        Carbon::setTestNow(Carbon::parse($when));

        return ServiceRecordService::submitServiceRecord(jobOrderId: $jo->id, employeeUserId: $employee->id,
            workDate: Carbon::now()->toDateString(), rawMinutes: 60, actorUserId: $employee->id);
    }

    public function test_the_approvers_get_one_email_listing_only_the_overdue_records(): void
    {
        $this->mailbox();
        [$company, $jo, $ed] = $this->jobOrder();
        $nico = User::factory()->for($company)->create(['role' => User::ROLE_SERVICE_LEAD, 'email' => 'nico@webmaster.example']);
        $cherish = User::factory()->for($company)->create(['role' => User::ROLE_SALES_MANAGER, 'email' => 'cherish@webmaster.example']);
        User::factory()->for($company)->create(['role' => User::ROLE_OWNER, 'email' => 'dennis@webmaster.example']);
        User::factory()->for($company)->create(['role' => User::ROLE_SERVICE_LEAD, 'email' => 'left@webmaster.example', 'is_active' => false]);
        $old = $this->submitAt('2026-09-10 10:00', $jo, $ed);
        $recent = $this->submitAt('2026-09-24 10:00', $jo, $ed);

        Carbon::setTestNow(Carbon::parse('2026-09-26 08:45'));
        Artisan::call('service-records:remind-overdue');

        $sent = Mailer::sent();
        $this->assertEqualsCanonicalizing(['nico@webmaster.example', 'cherish@webmaster.example'], array_column($sent, 'to'));
        $this->assertStringContainsString('1 Service Record waiting over 7 days', $sent[0]['subject']);
        $this->assertStringContainsString($old->service_record_number, $sent[0]['body']);
        $this->assertStringNotContainsString($recent->service_record_number, $sent[0]['body']);
        $this->assertStringContainsString('Engineer Ed', $sent[0]['body']);
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'service_record_reminder', 'action' => 'overdue_approval_reminder_sent']);
        // A nudge only: still waiting.
        $this->assertSame(ServiceRecord::STATUS_SUBMITTED, $old->fresh()->status);
    }

    public function test_nothing_is_sent_on_a_day_with_none_overdue(): void
    {
        $this->mailbox();
        [$company, $jo, $ed] = $this->jobOrder();
        User::factory()->for($company)->create(['role' => User::ROLE_SERVICE_LEAD]);
        $this->submitAt('2026-09-24 10:00', $jo, $ed);

        Carbon::setTestNow(Carbon::parse('2026-09-26 08:45'));
        Artisan::call('service-records:remind-overdue');

        $this->assertSame([], Mailer::sent());
    }

    public function test_without_a_mailbox_it_is_skipped_and_logged(): void
    {
        [$company, $jo, $ed] = $this->jobOrder();
        User::factory()->for($company)->create(['role' => User::ROLE_SERVICE_LEAD]);
        $this->submitAt('2026-09-10 10:00', $jo, $ed);

        Carbon::setTestNow(Carbon::parse('2026-09-26 08:45'));
        Artisan::call('service-records:remind-overdue');

        $this->assertSame([], Mailer::sent());
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'service_record_reminder', 'action' => 'overdue_approval_reminder_not_sent']);
    }

    public function test_it_is_scheduled_for_0845_singapore_time(): void
    {
        $events = collect(app(Schedule::class)->events());
        $reminder = $events->first(fn ($e) => str_contains((string) $e->command, 'service-records:remind-overdue'));

        $this->assertNotNull($reminder);
        $this->assertSame('45 8 * * *', $reminder->expression);
        $this->assertSame('Asia/Singapore', $reminder->timezone);
        $this->assertNotNull($events->first(fn ($e) => $e->description === 'email-inbox-check'));
    }
}
