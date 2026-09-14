<?php

namespace Tests\Feature;

use App\Models\BankAccount;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ContractService;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of App\Http\Controllers\Api\PaymentController
 * (Receipt Vouchers, AR-001), mirroring the payment half of
 * backend/app/routers/accounts_receivable.py. See
 * docs/php-conversion-plan.md's "after converting each module"
 * checklist. Business-rule arithmetic is covered separately in
 * AccountsReceivableServiceTest.php.
 */
class PaymentTest extends TestCase
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

    public function test_owner_can_record_and_allocate_a_receipt(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $bank = BankAccount::factory()->for($company)->create();
        $invoice = $this->invoice($company, $owner);

        $create = $this->postJson('/api/accounts-receivable/payments', [
            'customer_id' => $invoice->customer_id, 'payment_date' => now()->toDateString(),
            'amount_sgd' => 1000, 'bank_account_id' => $bank->id,
            'allocations' => [['invoice_id' => $invoice->id, 'amount_sgd' => 1000]],
        ], $this->headers($token));

        $create->assertOk();
        $this->assertStringStartsWith('RV-', $create->json('voucher_number'));
        $this->assertEqualsWithDelta(1000.0, $create->json('allocated_sgd'), 0.01);
        $this->assertSame('posted', $create->json('gl_status'));
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_a_single_receipt_can_allocate_across_two_invoices_in_one_request(): void
    {
        // Regression test: allocatePayment() must refresh the
        // payment's cached `allocations` relation after each write, or
        // the second line's unallocated-balance check sees a stale
        // (empty) collection and wrongly allows over-allocating.
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $bank = BankAccount::factory()->for($company)->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $contractA = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 300, startDate: now()->toDateString(), actorUserId: $owner->id,
        );
        $invoiceA = BillingService::issueContractAnnualInvoice($contractA, $owner->id);
        $contractB = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 300, startDate: now()->toDateString(), actorUserId: $owner->id,
        );
        $invoiceB = BillingService::issueContractAnnualInvoice($contractB, $owner->id);

        $create = $this->postJson('/api/accounts-receivable/payments', [
            'customer_id' => $customer->id, 'payment_date' => now()->toDateString(),
            'amount_sgd' => 600, 'bank_account_id' => $bank->id,
            'allocations' => [
                ['invoice_id' => $invoiceA->id, 'amount_sgd' => 300],
                ['invoice_id' => $invoiceB->id, 'amount_sgd' => 300],
            ],
        ], $this->headers($token));

        $create->assertOk();
        $this->assertEqualsWithDelta(600.0, $create->json('allocated_sgd'), 0.01);
        $this->assertEqualsWithDelta(0.0, $create->json('unallocated_sgd'), 0.01);
        $this->assertSame(Invoice::STATUS_PAID, $invoiceA->fresh()->status);
        $this->assertSame(Invoice::STATUS_PAID, $invoiceB->fresh()->status);
    }

    public function test_a_receipt_can_be_recorded_unallocated_and_allocated_later(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $bank = BankAccount::factory()->for($company)->create();
        $invoice = $this->invoice($company, $owner);

        $create = $this->postJson('/api/accounts-receivable/payments', [
            'customer_id' => $invoice->customer_id, 'payment_date' => now()->toDateString(),
            'amount_sgd' => 1000, 'bank_account_id' => $bank->id,
        ], $this->headers($token));

        $create->assertOk();
        $this->assertEqualsWithDelta(0.0, $create->json('allocated_sgd'), 0.01);
        $this->assertSame(Invoice::STATUS_OUTSTANDING, $invoice->fresh()->status);

        $allocate = $this->postJson("/api/accounts-receivable/payments/{$create->json('id')}/allocate", [
            'allocations' => [['invoice_id' => $invoice->id, 'amount_sgd' => 1000]],
        ], $this->headers($token));

        $allocate->assertOk();
        $this->assertSame(Invoice::STATUS_PAID, $invoice->fresh()->status);
    }

    public function test_receipt_without_a_bank_account_is_rejected(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();

        $this->postJson('/api/accounts-receivable/payments', [
            'customer_id' => $customer->id, 'payment_date' => now()->toDateString(), 'amount_sgd' => 100,
        ], $this->headers($token))->assertStatus(422);
    }

    public function test_owner_can_bank_and_unbank_a_receipt(): void
    {
        $company = Company::factory()->create();
        [$owner, $token] = $this->ownerToken($company);
        $bank = BankAccount::factory()->for($company)->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $create = $this->postJson('/api/accounts-receivable/payments', [
            'customer_id' => $customer->id, 'payment_date' => now()->toDateString(),
            'amount_sgd' => 300, 'bank_account_id' => $bank->id,
        ], $this->headers($token));

        $bankStep = $this->postJson("/api/accounts-receivable/payments/{$create->json('id')}/bank", [], $this->headers($token));
        $bankStep->assertOk()->assertJson(['status' => 'banked']);

        $unbank = $this->postJson("/api/accounts-receivable/payments/{$create->json('id')}/unbank", ['reason' => 'Wrong account'], $this->headers($token));
        $unbank->assertOk()->assertJson(['status' => 'unbanked']);
    }

    public function test_owner_can_ungl_a_receipt(): void
    {
        $company = Company::factory()->create();
        [, $token] = $this->ownerToken($company);
        $bank = BankAccount::factory()->for($company)->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $create = $this->postJson('/api/accounts-receivable/payments', [
            'customer_id' => $customer->id, 'payment_date' => now()->toDateString(),
            'amount_sgd' => 300, 'bank_account_id' => $bank->id,
        ], $this->headers($token));

        $ungl = $this->postJson("/api/accounts-receivable/payments/{$create->json('id')}/ungl", ['reason' => 'Raised in error'], $this->headers($token));

        $ungl->assertOk()->assertJson(['status' => 'reversed']);
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/accounts-receivable/payments', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_payment_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        [, $tokenA] = $this->ownerToken($companyA);
        $paymentB = Payment::factory()->for($companyB)->create();

        $this->getJson("/api/accounts-receivable/payments/{$paymentB->id}", $this->headers($tokenA))->assertStatus(404);
    }
}
