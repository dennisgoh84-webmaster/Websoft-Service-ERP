<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\ExcessUsageRecord;
use App\Models\JobOrder;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ContractService;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers App\Http\Controllers\Api\DashboardController -- the Company
 * Dashboard summary converted from backend/app/routers/dashboard.py.
 *
 * The endpoint is a read-only aggregate with no Module Control gate
 * (the Python route has none), so the usual RBAC matrix from
 * tests/Feature/CompanyIndividualTest.php collapses to: an
 * authenticated user always sees it, an unauthenticated one never
 * does, and each company only ever sees its own figures. Both of
 * those are pinned below, along with the arithmetic of every tile.
 */
class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function ownerToken(Company $company): string
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    private function owner(Company $company): User
    {
        return User::where('company_id', $company->id)->where('role', User::ROLE_OWNER)->firstOrFail();
    }

    /** An activated 10-hour / SGD 1,000 contract, which also issues + GL-posts its BILL-001 invoice. */
    private function activatedContract(Company $company, float $hours = 10, float $value = 1000): Contract
    {
        $owner = $this->owner($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $contract = ContractService::createContract(
            companyId: $company->id, customerId: $customer->id, contractedHours: $hours,
            contractValueSgd: $value, startDate: now()->toDateString(), actorUserId: $owner->id,
        );
        ContractService::activateContract($contract, $owner->id);
        BillingService::issueContractAnnualInvoice($contract, $owner->id);

        return $contract->refresh();
    }

    public function test_summary_aggregates_contracts_hours_and_invoices(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $contract = $this->activatedContract($company);
        $contract->update(['consumed_minutes' => 150]);

        $response = $this->getJson('/api/dashboard/summary', $this->headers($token));

        $response->assertOk()
            ->assertJson([
                'active_contracts' => 1,
                'total_contracted_hours' => 10,
                'total_consumed_hours' => 2.5,
                'total_remaining_hours' => 7.5,
                'invoices_count' => 1,
            ]);
        // No TaxCode is configured for a factory-made company, so GST is
        // 0 (BillingService never invents a rate) and the net amount is
        // exactly the SGD 1,000 contract value. Python sums amount_sgd
        // (net), not total_amount_sgd.
        $this->assertEqualsWithDelta(1000.0, $response->json('invoices_total_sgd'), 0.01);
    }

    public function test_contracts_expiring_soon_uses_the_srv_014_lead_window(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $owner = $this->owner($company);
        $customer = CompanyIndividual::factory()->for($company)->create();

        // One inside the 30-day window, one outside it, one already
        // expired (a negative day difference, which Python's
        // `0 <= (end_date - today).days` excludes).
        foreach ([10, 90, -5] as $offsetDays) {
            $c = ContractService::createContract(
                companyId: $company->id, customerId: $customer->id, contractedHours: 10,
                contractValueSgd: 1000, startDate: now()->subYear()->toDateString(), actorUserId: $owner->id,
            );
            ContractService::activateContract($c, $owner->id);
            $c->update(['end_date' => now()->addDays($offsetDays)->toDateString()]);
        }

        $response = $this->getJson('/api/dashboard/summary', $this->headers($token));

        $response->assertOk()->assertJson([
            'active_contracts' => 3,
            'contracts_expiring_soon' => 1,
        ]);
    }

    public function test_open_job_orders_counts_only_open_and_assigned(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        foreach ([JobOrder::STATUS_OPEN, JobOrder::STATUS_ASSIGNED, JobOrder::STATUS_CLOSED, JobOrder::STATUS_VOID] as $status) {
            JobOrder::factory()->for($company)->create(['customer_id' => $customer->id, 'status' => $status]);
        }

        $this->getJson('/api/dashboard/summary', $this->headers($token))
            ->assertOk()
            ->assertJson(['open_job_orders' => 2]);
    }

    public function test_missing_service_records_counts_only_late_submitted_ones(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $jobOrder = JobOrder::factory()->for($company)->create(['customer_id' => $customer->id]);
        $employee = User::factory()->for($company)->create();

        // SRV-015: submitted 5 days after the work date -- late.
        ServiceRecord::factory()->for($company)->create([
            'job_order_id' => $jobOrder->id, 'employee_user_id' => $employee->id,
            'work_date' => now()->subDays(5)->toDateString(), 'submitted_at' => now(),
        ]);
        // Submitted the same day -- on time.
        ServiceRecord::factory()->for($company)->create([
            'job_order_id' => $jobOrder->id, 'employee_user_id' => $employee->id,
            'work_date' => now()->toDateString(), 'submitted_at' => now(),
        ]);
        // Late, but already approved -- Python only looks at SUBMITTED.
        ServiceRecord::factory()->for($company)->create([
            'job_order_id' => $jobOrder->id, 'employee_user_id' => $employee->id,
            'work_date' => now()->subDays(5)->toDateString(), 'submitted_at' => now(),
            'status' => ServiceRecord::STATUS_APPROVED,
        ]);

        $this->getJson('/api/dashboard/summary', $this->headers($token))
            ->assertOk()
            ->assertJson(['missing_service_records' => 1]);
    }

    public function test_excess_awaiting_review_counts_only_undecided_records(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $contract = $this->activatedContract($company);
        $customer = CompanyIndividual::factory()->for($company)->create();
        $jobOrder = JobOrder::factory()->for($company)->create(['customer_id' => $customer->id]);
        $record = ServiceRecord::factory()->for($company)->create([
            'job_order_id' => $jobOrder->id,
            'employee_user_id' => User::factory()->for($company)->create()->id,
        ]);

        ExcessUsageRecord::create([
            'company_id' => $company->id, 'contract_id' => $contract->id,
            'service_record_id' => $record->id, 'excess_minutes' => 60,
        ]);
        ExcessUsageRecord::create([
            'company_id' => $company->id, 'contract_id' => $contract->id,
            'service_record_id' => $record->id, 'excess_minutes' => 30,
            'treatment' => ExcessUsageRecord::TREATMENT_BILLABLE,
        ]);

        $this->getJson('/api/dashboard/summary', $this->headers($token))
            ->assertOk()
            ->assertJson(['excess_awaiting_review' => 1]);
    }

    public function test_financial_summary_reports_ar_outstanding_and_a_balanced_gl(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $this->activatedContract($company);

        $response = $this->getJson('/api/dashboard/summary', $this->headers($token));

        $response->assertOk()->assertJson(['gl_is_balanced' => true]);
        // The invoice has no due date (the factory customer has no
        // payment terms), which agingBucketFor() treats as "current" --
        // outstanding, but never invented-overdue.
        $this->assertEqualsWithDelta(1000.0, $response->json('ar_outstanding_sgd'), 0.01);
        $this->assertEqualsWithDelta(0.0, $response->json('ar_overdue_sgd'), 0.01);
        $this->assertEqualsWithDelta(0.0, $response->json('ap_outstanding_sgd'), 0.01);
        $this->assertEqualsWithDelta(0.0, $response->json('ap_overdue_sgd'), 0.01);
    }

    public function test_figures_are_scoped_to_the_users_own_company(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $tokenA = $this->ownerToken($companyA);
        $this->ownerToken($companyB);
        $this->activatedContract($companyB);

        $this->getJson('/api/dashboard/summary', $this->headers($tokenA))
            ->assertOk()
            ->assertJson([
                'active_contracts' => 0,
                'invoices_count' => 0,
                'ar_outstanding_sgd' => 0,
                'gl_is_balanced' => true,
            ]);
    }

    /**
     * Deliberate, faithful conversion: backend/app/routers/dashboard.py
     * depends only on get_current_user -- no require_module_access --
     * so a user with no Group still sees the summary. Every other
     * module's equivalent test asserts 403 here; this one asserts 200
     * so the difference is a pinned decision, not an oversight.
     */
    public function test_user_with_no_group_still_sees_the_summary_no_module_gate(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/dashboard/summary', $this->headers($login->json('access_token')))->assertOk();
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/dashboard/summary')->assertStatus(401);
    }
}
