<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Invoice;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ContractService;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of App\Http\Controllers\Api\AccountsReceivableController,
 * mirroring backend/app/routers/accounts_receivable.py's AR-002/003
 * endpoints. See docs/php-conversion-plan.md's "after converting each
 * module" checklist. Business-rule arithmetic is covered separately
 * in AccountsReceivableServiceTest.php.
 */
class AccountsReceivableTest extends TestCase
{
    use RefreshDatabase;

    private function ownerToken(Company $company): array
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);

        return [$owner, $login->json('access_token')];
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function invoice(Company $company, User $actor): Invoice
    {
        $customer = CompanyIndividual::factory()->for($company)->create();
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 1000, startDate: now()->toDateString(), actorUserId: $actor->id,
        );

        return BillingService::issueContractAnnualInvoice($contract, $actor->id);
    }

    public function test_owner_can_write_off_an_invoice(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $invoice = $this->invoice($company, $owner);

        $response = $this->postJson("/api/accounts-receivable/invoices/{$invoice->id}/write-off", [
            'reason' => 'Customer went into liquidation',
        ], $this->headers($token));

        $response->assertOk()->assertJson(['status' => 'written_off', 'outstanding_sgd' => 0]);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'invoice', 'entity_id' => $invoice->id, 'action' => 'written_off',
        ]);
    }

    public function test_write_off_requires_a_reason(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $invoice = $this->invoice($company, $owner);

        $this->postJson("/api/accounts-receivable/invoices/{$invoice->id}/write-off", [
            'reason' => '',
        ], $this->headers($token))->assertStatus(422);
    }

    public function test_owner_can_flag_and_clear_a_dispute(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $invoice = $this->invoice($company, $owner);

        $flag = $this->postJson("/api/accounts-receivable/invoices/{$invoice->id}/dispute", [
            'is_disputed' => true, 'note' => 'Customer disputes the scope covered',
        ], $this->headers($token));
        $flag->assertOk()->assertJson(['is_disputed' => true]);
        // AR-003: a disputed invoice is not held -- status is untouched.
        $this->assertSame('outstanding', $flag->json('status'));

        $clear = $this->postJson("/api/accounts-receivable/invoices/{$invoice->id}/dispute", [
            'is_disputed' => false,
        ], $this->headers($token));
        $clear->assertOk()->assertJson(['is_disputed' => false]);
    }

    public function test_aging_report_buckets_by_days_overdue(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $invoice = $this->invoice($company, $owner);
        $invoice->update(['due_date' => now()->subDays(10)->toDateString()]);

        $response = $this->getJson('/api/accounts-receivable/aging', $this->headers($token));

        $response->assertOk();
        $this->assertCount(1, $response->json('rows'));
        $this->assertGreaterThan(0, $response->json('rows.0.days_1_30'));
        $this->assertGreaterThan(0, $response->json('days_1_30'));
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/accounts-receivable/aging', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_invoice_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        [, $tokenA] = $this->ownerToken($companyA);
        [$ownerB] = $this->ownerToken($companyB);
        $invoiceB = $this->invoice($companyB, $ownerB);

        $this->postJson("/api/accounts-receivable/invoices/{$invoiceB->id}/write-off", [
            'reason' => 'x',
        ], $this->headers($tokenA))->assertStatus(404);
    }
}
