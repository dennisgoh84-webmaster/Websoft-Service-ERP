<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Incident;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PortalUser;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Customer Helpdesk Portal data endpoints (PORTAL-002/005/006, design
 * §6 + §9 test plan items 3 and 8).
 *
 * Every test in this file runs with a SECOND customer's data present in
 * the same company, because "the portal user sees their own data" is
 * only half the requirement -- "and never anybody else's" is the half
 * that matters. Cross-customer isolation is asserted on every list, and
 * an id-bearing path (job order detail) must 404, never 403.
 */
class PortalDataTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private CompanyIndividual $mine;

    private CompanyIndividual $theirs;

    private PortalUser $portalUser;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->mine = CompanyIndividual::factory()->for($this->company)->create([
            'name' => 'My Customer Pte Ltd', 'pdpa_consent_given' => true,
        ]);
        $this->theirs = CompanyIndividual::factory()->for($this->company)->create([
            'name' => 'Other Customer Pte Ltd', 'pdpa_consent_given' => true,
        ]);

        $contact = Contact::create([
            'customer_id' => $this->mine->id,
            'name' => 'Alice Tan',
            'email' => 'alice@mycustomer.example',
            'phone' => '+65 9000 0001',
        ]);
        $this->portalUser = PortalUser::create([
            'company_id' => $this->company->id,
            'contact_id' => $contact->id,
            'email' => $contact->email,
            'hashed_password' => PasswordPolicy::hash('portal123'),
            'must_change_password' => false,
        ]);

        $login = $this->postJson('/api/portal/auth/login', ['email' => $contact->email, 'password' => 'portal123']);
        $this->token = $login->json('portal_token');
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    private function makeContract(CompanyIndividual $customer, int $contractedMinutes = 600, int $consumedMinutes = 150): Contract
    {
        return Contract::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'contracted_minutes' => $contractedMinutes,
            'consumed_minutes' => $consumedMinutes,
            'status' => Contract::STATUS_ACTIVE,
        ]);
    }

    private function makeJobOrder(CompanyIndividual $customer, ?Contract $contract = null, array $overrides = []): JobOrder
    {
        return JobOrder::factory()->create(array_merge([
            'company_id' => $this->company->id,
            'customer_id' => $customer->id,
            'contract_id' => $contract?->id,
        ], $overrides));
    }

    // ---- contracts --------------------------------------------------------

    public function test_contracts_shows_only_this_customers_contracts_with_hour_balances(): void
    {
        $mine = $this->makeContract($this->mine, contractedMinutes: 600, consumedMinutes: 150);
        $this->makeContract($this->theirs);

        $res = $this->getJson('/api/portal/contracts', $this->headers());

        $res->assertOk()->assertJsonCount(1);
        $res->assertJsonPath('0.id', $mine->id)
            ->assertJsonPath('0.contracted_hours', 10)
            ->assertJsonPath('0.consumed_hours', 2.5)
            ->assertJsonPath('0.remaining_hours', 7.5);
    }

    // ---- job orders -------------------------------------------------------

    public function test_job_orders_shows_only_this_customers_and_names_the_assigned_engineer(): void
    {
        $engineer = User::factory()->for($this->company)->create(['full_name' => 'Nico Tan']);
        $contract = $this->makeContract($this->mine);
        $mine = $this->makeJobOrder($this->mine, $contract, ['assigned_to_user_id' => $engineer->id]);
        $this->makeJobOrder($this->theirs);

        $res = $this->getJson('/api/portal/job-orders', $this->headers());

        $res->assertOk()->assertJsonCount(1);
        $res->assertJsonPath('0.id', $mine->id)
            ->assertJsonPath('0.assigned_engineer_name', 'Nico Tan')
            ->assertJsonPath('0.contract_id', $contract->id)
            ->assertJsonPath('0.contract_number', $contract->contract_number);
    }

    public function test_job_orders_contract_filter_with_another_customers_contract_returns_empty(): void
    {
        $this->makeJobOrder($this->mine, $this->makeContract($this->mine));
        $theirContract = $this->makeContract($this->theirs);
        $this->makeJobOrder($this->theirs, $theirContract);

        // PORTAL-006: the customer filter is applied FIRST, so somebody
        // else's contract_id just returns empty -- never their rows.
        $this->getJson("/api/portal/job-orders?contract_id={$theirContract->id}", $this->headers())
            ->assertOk()->assertJsonCount(0);
    }

    public function test_job_order_detail_returns_its_service_records(): void
    {
        $engineer = User::factory()->for($this->company)->create(['full_name' => 'Nico Tan']);
        $contract = $this->makeContract($this->mine);
        $jobOrder = $this->makeJobOrder($this->mine, $contract);
        $record = ServiceRecord::factory()->create([
            'company_id' => $this->company->id,
            'job_order_id' => $jobOrder->id,
            'employee_user_id' => $engineer->id,
            'raw_minutes' => 50,
            'rounded_minutes' => 60,
            'completion_status' => ServiceRecord::COMPLETED,
        ]);

        $res = $this->getJson("/api/portal/job-orders/{$jobOrder->id}", $this->headers());

        $res->assertOk()
            ->assertJsonPath('id', $jobOrder->id)
            ->assertJsonCount(1, 'service_records')
            ->assertJsonPath('service_records.0.id', $record->id)
            ->assertJsonPath('service_records.0.engineer_name', 'Nico Tan')
            // SRV-007 rounded minutes only -- never the raw or deducted
            // figure (design §6's "deliberately not exposed" list).
            ->assertJsonPath('service_records.0.minutes', 60)
            ->assertJsonPath('service_records.0.job_order_number', $jobOrder->job_order_number);
        $this->assertArrayNotHasKey('raw_minutes', $res->json('service_records.0'));
        $this->assertArrayNotHasKey('deducted_minutes', $res->json('service_records.0'));
    }

    public function test_another_customers_job_order_id_is_404_never_403(): void
    {
        $theirs = $this->makeJobOrder($this->theirs);

        // design §9.4: confirming the id exists for someone else is
        // exactly the leak this must not produce.
        $this->getJson("/api/portal/job-orders/{$theirs->id}", $this->headers())
            ->assertStatus(404)
            ->assertJson(['detail' => 'Job order not found']);
    }

    // ---- service records --------------------------------------------------

    public function test_service_records_lists_only_this_customers_records(): void
    {
        $engineer = User::factory()->for($this->company)->create(['full_name' => 'Nico Tan']);
        $contract = $this->makeContract($this->mine);
        $mineJo = $this->makeJobOrder($this->mine, $contract);
        $theirsJo = $this->makeJobOrder($this->theirs, $this->makeContract($this->theirs));
        $mineRecord = ServiceRecord::factory()->create([
            'company_id' => $this->company->id, 'job_order_id' => $mineJo->id,
            'employee_user_id' => $engineer->id, 'rounded_minutes' => 30,
        ]);
        ServiceRecord::factory()->create([
            'company_id' => $this->company->id, 'job_order_id' => $theirsJo->id,
            'employee_user_id' => $engineer->id, 'rounded_minutes' => 45,
        ]);

        $res = $this->getJson('/api/portal/service-records', $this->headers());

        $res->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $mineRecord->id)
            ->assertJsonPath('0.job_order_number', $mineJo->job_order_number)
            ->assertJsonPath('0.contract_number', $contract->contract_number)
            ->assertJsonPath('0.minutes', 30);
    }

    public function test_service_records_contract_filter_scopes_to_that_contracts_job_orders(): void
    {
        $engineer = User::factory()->for($this->company)->create();
        $contractA = $this->makeContract($this->mine);
        $contractB = $this->makeContract($this->mine);
        foreach ([$contractA, $contractB] as $contract) {
            $jo = $this->makeJobOrder($this->mine, $contract);
            ServiceRecord::factory()->create([
                'company_id' => $this->company->id, 'job_order_id' => $jo->id,
                'employee_user_id' => $engineer->id,
            ]);
        }

        $this->getJson("/api/portal/service-records?contract_id={$contractA->id}", $this->headers())
            ->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.contract_id', $contractA->id);

        // A bogus contract id is empty, not an error (PORTAL-006).
        $this->getJson('/api/portal/service-records?contract_id='.Str::uuid(), $this->headers())
            ->assertOk()->assertJsonCount(0);
    }

    // ---- invoices / payments (PORTAL-005) ---------------------------------

    public function test_invoices_show_the_customers_own_figures_and_never_cost_or_gp(): void
    {
        $contract = $this->makeContract($this->mine);
        $mine = Invoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->mine->id,
            'contract_id' => $contract->id,
            'invoice_number' => 'INV-2026-0001',
            'invoice_type' => Invoice::TYPE_CONTRACT_ANNUAL,
            'description' => 'Annual service contract',
            'amount_sgd' => '3000.00',
            'tax_code' => 'SR',
            'gst_rate' => '9.00',
            'gst_amount_sgd' => '270.00',
            'total_amount_sgd' => '3270.00',
            'cost_sgd' => '1200.00',
            'amount_paid_sgd' => '270.00',
            'status' => Invoice::STATUS_PARTIALLY_PAID,
        ]);
        Invoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->theirs->id,
            'invoice_number' => 'INV-2026-0002',
            'invoice_type' => Invoice::TYPE_CONTRACT_ANNUAL,
            'description' => 'Someone else entirely',
            'amount_sgd' => '100.00', 'tax_code' => 'SR', 'gst_rate' => '9.00',
            'gst_amount_sgd' => '9.00', 'total_amount_sgd' => '109.00',
        ]);

        $res = $this->getJson('/api/portal/invoices', $this->headers());

        $res->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $mine->id)
            ->assertJsonPath('0.invoice_number', 'INV-2026-0001')
            ->assertJsonPath('0.contract_number', $contract->contract_number);

        $row = $res->json('0');
        $this->assertEquals(3000.0, $row['amount_sgd']);
        $this->assertEquals(270.0, $row['gst_amount_sgd']);
        $this->assertEquals(3270.0, $row['total_amount_sgd']);
        $this->assertEquals(270.0, $row['amount_paid_sgd']);
        $this->assertEquals(3000.0, $row['outstanding_sgd']);
        // Staff-only figures must never reach a customer (design §6).
        $this->assertArrayNotHasKey('cost_sgd', $row);
        $this->assertArrayNotHasKey('gp_sgd', $row);
        $this->assertArrayNotHasKey('gst_rate', $row);
        // Money crosses the wire as JSON numbers, not decimal strings --
        // same check QuotationTest makes, same convention
        // (docs/php-conversion-plan.md's Decimal/money handling).
        $this->assertMatchesRegularExpression('/"total_amount_sgd":3270(\.0)?[,}]/', $res->getContent());
    }

    public function test_payments_show_their_own_receipts_and_what_each_settled(): void
    {
        $invoice = Invoice::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->mine->id,
            'invoice_number' => 'INV-2026-0001',
            'invoice_type' => Invoice::TYPE_CONTRACT_ANNUAL,
            'description' => 'Annual service contract',
            'amount_sgd' => '1000.00', 'tax_code' => 'SR', 'gst_rate' => '9.00',
            'gst_amount_sgd' => '90.00', 'total_amount_sgd' => '1090.00',
        ]);
        $payment = Payment::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->mine->id,
            'amount_sgd' => '500.00',
            'method' => Payment::METHOD_PAYNOW,
            'reference' => 'PN-12345',
        ]);
        PaymentAllocation::create([
            'company_id' => $this->company->id,
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount_sgd' => '500.00',
        ]);
        Payment::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->theirs->id,
            'amount_sgd' => '9999.00',
        ]);

        $res = $this->getJson('/api/portal/payments', $this->headers());

        $res->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $payment->id)
            ->assertJsonPath('0.method', Payment::METHOD_PAYNOW)
            ->assertJsonPath('0.reference', 'PN-12345')
            ->assertJsonCount(1, '0.allocations')
            ->assertJsonPath('0.allocations.0.invoice_number', 'INV-2026-0001');
        $this->assertEquals(500.0, $res->json('0.amount_sgd'));
        $this->assertEquals(500.0, $res->json('0.allocations.0.amount_sgd'));
    }

    // ---- incidents (§9.3) -------------------------------------------------

    public function test_raising_an_incident_lands_in_the_staff_helpdesk_queue_as_source_portal(): void
    {
        $res = $this->postJson('/api/portal/incidents', [
            'subject' => 'Email is down',
            'description' => 'Nobody in the office can send mail since 9am.',
        ], $this->headers());

        $res->assertOk()
            ->assertJsonPath('subject', 'Email is down')
            ->assertJsonPath('status', Incident::STATUS_OPEN)
            ->assertJsonPath('converted_job_order_number', null);
        $this->assertStringStartsWith('INC-', $res->json('incident_number'));

        $incident = Incident::findOrFail($res->json('id'));
        $this->assertSame(Incident::SOURCE_PORTAL, $incident->source);
        $this->assertSame($this->mine->id, $incident->customer_id);
        $this->assertSame($this->portalUser->id, $incident->raised_by_portal_user_id);
        $this->assertNull($incident->created_by_user_id);
        // Contact details come from the token, never from the request.
        $this->assertSame('Alice Tan', $incident->sender_name);
        $this->assertSame('alice@mycustomer.example', $incident->sender_email);
        $this->assertSame('+65 9000 0001', $incident->sender_phone);

        // Audited as a portal actor -- no staff user to attribute it to.
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'incident',
            'entity_id' => $incident->id,
            'action' => 'created',
            'actor_user_id' => null,
            'actor_name' => 'Alice Tan (portal)',
            'company_id' => $this->company->id,
        ]);
    }

    public function test_raising_an_incident_requires_a_subject(): void
    {
        $this->postJson('/api/portal/incidents', ['description' => 'no subject'], $this->headers())
            ->assertStatus(422);
    }

    public function test_incidents_list_shows_only_this_customers_and_the_routed_job_order_number(): void
    {
        $contract = $this->makeContract($this->mine);
        $jobOrder = $this->makeJobOrder($this->mine, $contract);
        $mine = Incident::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->mine->id,
            'subject' => 'Email is down',
            'status' => Incident::STATUS_CONVERTED,
            'converted_job_order_id' => $jobOrder->id,
        ]);
        Incident::factory()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->theirs->id,
            'subject' => 'Not your incident',
        ]);

        $res = $this->getJson('/api/portal/incidents', $this->headers());

        $res->assertOk()->assertJsonCount(1)
            ->assertJsonPath('0.id', $mine->id)
            ->assertJsonPath('0.converted_job_order_number', $jobOrder->job_order_number);
    }

    // ---- boundary ---------------------------------------------------------

    public function test_every_portal_data_endpoint_refuses_a_staff_token(): void
    {
        $owner = User::factory()->for($this->company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $staffToken = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])
            ->json('access_token');
        $headers = ['Authorization' => "Bearer {$staffToken}"];

        foreach (['/contracts', '/job-orders', '/service-records', '/invoices', '/payments', '/incidents'] as $path) {
            $this->getJson("/api/portal{$path}", $headers)
                ->assertStatus(401, "staff token must not be accepted by /api/portal{$path}");
        }
        $this->postJson('/api/portal/incidents', ['subject' => 'x'], $headers)->assertStatus(401);
    }

    public function test_every_portal_data_endpoint_refuses_an_unauthenticated_request(): void
    {
        foreach (['/contracts', '/job-orders', '/service-records', '/invoices', '/payments', '/incidents'] as $path) {
            $this->getJson("/api/portal{$path}")->assertStatus(401);
        }
    }

    public function test_disabling_access_cuts_off_the_data_endpoints_too(): void
    {
        $this->makeContract($this->mine);
        $this->getJson('/api/portal/contracts', $this->headers())->assertOk();

        $this->portalUser->is_active = false;
        $this->portalUser->save();

        $this->getJson('/api/portal/contracts', $this->headers())->assertStatus(401);
        $this->postJson('/api/portal/incidents', ['subject' => 'x'], $this->headers())->assertStatus(401);
    }
}
