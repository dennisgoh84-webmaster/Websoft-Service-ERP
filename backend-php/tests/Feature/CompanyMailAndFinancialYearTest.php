<?php

namespace Tests\Feature;

use App\Models\AuditLogEntry;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\Invoice;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\Mailer;
use App\Services\MailerNotConfiguredException;
use App\Services\PasswordPolicy;
use App\Services\SalesDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The financial-year setting and the per-company mailbox added to
 * Company Setup (Dennis, 2026-09-15).
 */
class CompanyMailAndFinancialYearTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'core_administration';

    private Company $company;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['name' => 'Webmaster Consultancy']);
        ModuleCatalog::firstOrCreate(['key' => self::MODULE], ['name' => 'Core / Administration', 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $this->company->id, 'module_key' => self::MODULE],
            ['enabled' => true],
        );
        $owner = User::factory()->for($this->company)->create([
            'role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $this->token = $this->post('/api/auth/login', [
            'username' => $owner->email, 'password' => 'demo1234',
        ])->json('access_token');
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    // ── Financial year ──────────────────────────────────────────────

    public function test_a_financial_year_is_labelled_by_the_year_it_ends_in(): void
    {
        // Webmaster runs 1 July - 30 June, so FY2027 STARTS in 2026.
        $this->company->update(['financial_year_start_month' => 7]);

        $range = SalesDashboardService::financialYearRange($this->company->id, 2027);

        $this->assertSame('2026-07-01', $range['start']->toDateString());
        $this->assertSame('2027-06-30', $range['end']->toDateString());
    }

    public function test_a_january_start_makes_the_financial_year_the_calendar_year(): void
    {
        // The previous behaviour is still exactly reproducible, which
        // matters for any company that really does run Jan-Dec.
        $this->company->update(['financial_year_start_month' => 1]);

        $range = SalesDashboardService::financialYearRange($this->company->id, 2027);

        $this->assertSame('2027-01-01', $range['start']->toDateString());
        $this->assertSame('2027-12-31', $range['end']->toDateString());
    }

    public function test_the_default_start_month_is_july(): void
    {
        $this->assertSame(7, Company::factory()->create()->financial_year_start_month);
    }

    public function test_the_dashboard_counts_only_invoices_inside_the_financial_year(): void
    {
        $this->company->update(['financial_year_start_month' => 7]);
        $customer = CompanyIndividual::factory()->create([
            'company_id' => $this->company->id, 'name' => 'Acme', 'is_customer' => true,
        ]);

        // Inside FY2027 (Jul 2026 - Jun 2027).
        $this->invoice($customer, '2026-08-01', '1000.00');
        $this->invoice($customer, '2027-05-31', '500.00');
        // Outside: the day before it starts, and the day after it ends.
        $this->invoice($customer, '2026-06-30', '9999.00');
        $this->invoice($customer, '2027-07-01', '8888.00');

        $top = SalesDashboardService::topBillingCustomers($this->company->id, 2027);

        $this->assertCount(1, $top);
        $this->assertEqualsWithDelta(1500.0, $top[0]['net_revenue_sgd'], 0.01);
    }

    public function test_the_start_month_must_be_a_real_month(): void
    {
        $this->patchJson("/api/companies/{$this->company->id}", [
            'financial_year_start_month' => 13,
        ], $this->headers())->assertStatus(422);
        $this->patchJson("/api/companies/{$this->company->id}", [
            'financial_year_start_month' => 0,
        ], $this->headers())->assertStatus(422);
    }

    private function invoice(CompanyIndividual $customer, string $issuedAt, string $amount): Invoice
    {
        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'invoice_number' => 'INV-'.substr(md5($issuedAt.$amount), 0, 8),
            'invoice_type' => 'manual',
            'description' => 'Test invoice',
            'amount_sgd' => $amount,
            'gst_rate' => '0.00',
            'gst_amount_sgd' => '0.00',
            'total_amount_sgd' => $amount,
            'status' => 'issued',
        ]);
        // issued_at is not mass-assignable, so it is set explicitly --
        // and it is the column the financial-year window filters on.
        $invoice->forceFill(['issued_at' => $issuedAt])->save();

        return $invoice;
    }

    // ── Company mailbox ─────────────────────────────────────────────

    public function test_the_smtp_password_is_encrypted_at_rest_and_never_returned(): void
    {
        $this->patchJson("/api/companies/{$this->company->id}", [
            'smtp_host' => 'smtp.office365.com',
            'smtp_from_email' => 'billing@webmaster.example',
            'smtp_password' => 'super-secret',
        ], $this->headers())->assertOk()
            ->assertJsonMissingPath('smtp_password');

        $company = $this->company->fresh();
        // Usable by the application...
        $this->assertSame('super-secret', $company->smtp_password);
        // ...but not readable from a database dump.
        $raw = (string) DB::table('companies')->where('id', $company->id)->value('smtp_password');
        $this->assertNotSame('super-secret', $raw);
        $this->assertStringNotContainsString('super-secret', $raw);
    }

    public function test_the_password_value_never_reaches_the_audit_trail(): void
    {
        $this->patchJson("/api/companies/{$this->company->id}", [
            'smtp_password' => 'super-secret',
        ], $this->headers())->assertOk();

        $entry = AuditLogEntry::where('entity_type', 'company')
            ->where('action', 'updated')->latest('at')->firstOrFail();
        $recorded = (string) $entry->new_value;
        $this->assertStringNotContainsString('super-secret', $recorded);
        $this->assertStringContainsString('(set)', $recorded);
    }

    public function test_a_company_without_its_own_mailbox_is_not_configured(): void
    {
        $this->assertFalse(Mailer::isConfiguredFor($this->company));

        // A host alone is not enough -- a From address is required too,
        // since that is what the recipient sees.
        $this->company->update(['smtp_host' => 'smtp.example.com']);
        $this->assertFalse(Mailer::isConfiguredFor($this->company->fresh()));

        $this->company->update(['smtp_from_email' => 'billing@example.com']);
        $this->assertTrue(Mailer::isConfiguredFor($this->company->fresh()));
    }

    public function test_the_system_mailbox_and_the_company_mailbox_are_independent(): void
    {
        // The system mailbox being configured must NOT make a company
        // count as configured -- that is exactly the fallback we
        // deliberately do not have.
        config([
            'websoft.smtp_host' => 'smtp.system.example',
            'websoft.smtp_from_email' => 'noreply@system.example',
        ]);

        $this->assertTrue(Mailer::isConfigured(), 'system mailbox is set up');
        $this->assertFalse(
            Mailer::isConfiguredFor($this->company),
            'a configured system mailbox must not stand in for a company one',
        );
    }

    public function test_sending_as_an_unconfigured_company_names_the_company(): void
    {
        $this->expectException(MailerNotConfiguredException::class);
        $this->expectExceptionMessage('Email is not configured for Webmaster Consultancy.');

        Mailer::sendAs($this->company, 'someone@example.com', 'Subject', 'Body');
    }

    public function test_the_company_dsn_uses_its_own_settings_and_honours_tls(): void
    {
        $this->company->update([
            'smtp_host' => 'smtp.acme.example',
            'smtp_port' => 2525,
            'smtp_username' => 'billing@acme.example',
            'smtp_password' => 'pw',
            'smtp_from_email' => 'billing@acme.example',
            'smtp_use_tls' => true,
        ]);

        $dsn = Mailer::dsnFor([
            'host' => 'smtp.acme.example', 'port' => 2525,
            'username' => 'billing@acme.example', 'password' => 'pw', 'use_tls' => true,
        ]);
        $this->assertStringContainsString('smtp.acme.example:2525', $dsn);
        // require_tls, not the smtps:// scheme -- STARTTLS, matching
        // what Python's mailer does.
        $this->assertStringContainsString('require_tls=true', $dsn);
        $this->assertStringNotContainsString('smtps://', $dsn);

        $plain = Mailer::dsnFor(['host' => 'h', 'port' => 25, 'use_tls' => false]);
        $this->assertStringContainsString('auto_tls=false', $plain);
    }

    public function test_test_email_refuses_until_the_mailbox_is_configured(): void
    {
        $this->postJson("/api/companies/{$this->company->id}/test-email", [
            'to_email' => 'admin@example.com',
        ], $this->headers())->assertStatus(422)
            ->assertJsonPath('detail', 'Email is not configured for Webmaster Consultancy. Set its SMTP host and From address first.');
    }

    public function test_a_non_owner_cannot_touch_a_company_they_have_no_access_to(): void
    {
        // An OWNER deliberately reaches every company in the install --
        // they manage all of the operator's entities -- so the
        // cross-company guard has to be exercised with someone who is
        // not one.
        $other = Company::factory()->create();
        CompanyModule::updateOrCreate(
            ['company_id' => $this->company->id, 'module_key' => self::MODULE],
            ['enabled' => true],
        );
        $group = Group::factory()->for($this->company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id, 'module_key' => self::MODULE,
            'access_level' => GroupModuleAuthority::FULL,
        ]);
        $admin = User::factory()->for($this->company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER, 'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create([
            'user_id' => $admin->id, 'company_id' => $this->company->id, 'group_id' => $group->id,
        ]);
        $token = $this->post('/api/auth/login', ['username' => $admin->email, 'password' => 'demo1234'])
            ->json('access_token');
        $headers = ['Authorization' => "Bearer {$token}"];

        $this->patchJson("/api/companies/{$other->id}", ['smtp_host' => 'evil.example'], $headers)
            ->assertStatus(403);
        $this->postJson("/api/companies/{$other->id}/test-email", ['to_email' => 'a@b.example'], $headers)
            ->assertStatus(403);
    }
}
