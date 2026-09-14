<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ContractService;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of App\Http\Controllers\Api\InvoiceController,
 * mirroring backend/app/routers/billing.py. See
 * docs/php-conversion-plan.md's "after converting each module"
 * checklist. Business-rule arithmetic is covered separately in
 * BillingServiceTest.php.
 */
class InvoiceTest extends TestCase
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

    public function test_owner_can_list_and_view_an_invoice(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 3000, startDate: now()->toDateString(), actorUserId: $owner->id,
        );
        $invoice = BillingService::issueContractAnnualInvoice($contract, $owner->id);

        $list = $this->getJson('/api/invoices', $this->headers($token));
        $list->assertOk()->assertJsonCount(1);
        $this->assertSame('not_posted', $list->json('0.gl_status'));

        $show = $this->getJson("/api/invoices/{$invoice->id}", $this->headers($token));
        $show->assertOk()->assertJson(['invoice_type' => 'contract_annual', 'status' => 'outstanding']);
        $this->assertEqualsWithDelta(3000.0, $show->json('amount_sgd'), 0.01);
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/invoices', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_invoice_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        [, $tokenA] = $this->ownerToken($companyA);
        [$ownerB] = $this->ownerToken($companyB);
        $customerB = CompanyIndividual::factory()->for($companyB)->create();
        $contractB = ContractService::createContract(
            companyId: $companyB->id, customerId: $customerB->id, contractedHours: 10,
            contractValueSgd: 1000, startDate: now()->toDateString(), actorUserId: $ownerB->id,
        );
        $invoiceB = BillingService::issueContractAnnualInvoice($contractB, $ownerB->id);

        $this->getJson("/api/invoices/{$invoiceB->id}", $this->headers($tokenA))->assertStatus(404);
    }

    public function test_activating_a_contract_issues_its_annual_invoice(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 3000, startDate: now()->toDateString(), actorUserId: $owner->id,
        );

        $this->postJson("/api/contracts/{$contract->id}/activate", [], $this->headers($token))->assertOk();

        $invoices = $this->getJson('/api/invoices', $this->headers($token));
        $invoices->assertOk()->assertJsonCount(1);
        $this->assertSame('contract_annual', $invoices->json('0.invoice_type'));
        $this->assertEqualsWithDelta(3000.0, $invoices->json('0.amount_sgd'), 0.01);
    }

    public function test_activating_an_ad_hoc_contract_does_not_issue_an_invoice(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 0,
            contractValueSgd: 0, startDate: now()->toDateString(), actorUserId: $owner->id,
            contractKind: Contract::KIND_AD_HOC, hourlyRateSgd: 150,
        );

        $this->postJson("/api/contracts/{$contract->id}/activate", [], $this->headers($token))->assertOk();

        $this->getJson('/api/invoices', $this->headers($token))->assertOk()->assertJsonCount(0);
    }
}
