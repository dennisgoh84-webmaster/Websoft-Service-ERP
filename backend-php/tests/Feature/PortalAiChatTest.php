<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Incident;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\ModuleCatalog;
use App\Models\PortalUser;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiPortalTools;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI Assistant slice 3 (docs/planned-work.md #12 Tier 2 item 6): the
 * chat on the Customer Helpdesk Portal. Every test keeps a SECOND
 * customer's data in the same company, same discipline as
 * PortalDataTest -- "only my own records" is the whole point of this
 * slice, more than for the staff one.
 */
class PortalAiChatTest extends TestCase
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
        AiClient::fake();

        $this->company = Company::factory()->create();
        foreach (['ai_assistant'] as $key) {
            ModuleCatalog::firstOrCreate(['key' => $key], ['name' => $key, 'is_built' => true]);
            CompanyModule::updateOrCreate(['company_id' => $this->company->id, 'module_key' => $key], ['enabled' => true, 'license_type' => CompanyModule::ADD_ON]);
        }
        $this->mine = CompanyIndividual::factory()->for($this->company)->create(['name' => 'My Customer Pte Ltd', 'pdpa_consent_given' => true]);
        $this->theirs = CompanyIndividual::factory()->for($this->company)->create(['name' => 'Other Customer Pte Ltd', 'pdpa_consent_given' => true]);

        $contact = Contact::create(['customer_id' => $this->mine->id, 'name' => 'Alice Tan', 'email' => 'alice@mycustomer.example', 'phone' => '+65 9000 0001']);
        $this->portalUser = PortalUser::create([
            'company_id' => $this->company->id, 'contact_id' => $contact->id, 'email' => $contact->email,
            'hashed_password' => PasswordPolicy::hash('portal123'), 'must_change_password' => false,
        ]);
        $login = $this->postJson('/api/portal/auth/login', ['email' => $contact->email, 'password' => 'portal123']);
        $this->token = $login->json('portal_token');
    }

    protected function tearDown(): void
    {
        AiClient::restore();
        parent::tearDown();
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_the_widget_answers_from_the_customers_own_contract_and_never_sees_the_other_customer(): void
    {
        $mine = Contract::factory()->create(['company_id' => $this->company->id, 'customer_id' => $this->mine->id, 'contracted_minutes' => 600, 'consumed_minutes' => 150, 'status' => Contract::STATUS_ACTIVE]);
        Contract::factory()->create(['company_id' => $this->company->id, 'customer_id' => $this->theirs->id, 'contracted_minutes' => 1200, 'consumed_minutes' => 100, 'status' => Contract::STATUS_ACTIVE]);

        AiClient::fake([
            ['tool_calls' => [['name' => 'get_my_contracts', 'input' => []]]],
            ['text' => 'You have 7.5 hours remaining on '.$mine->contract_number.'.', '__input_tokens' => 500, '__output_tokens' => 30],
        ]);

        $res = $this->postJson('/api/portal/ai/chat', ['messages' => [['role' => 'user', 'content' => 'How many hours do I have left?']]], $this->headers())->assertOk();
        $res->assertJsonPath('answer', 'You have 7.5 hours remaining on '.$mine->contract_number.'.')->assertJsonPath('refused', false);

        $sent = AiClient::requests();
        $this->assertStringContainsString('NOT a member of staff', $sent[0]['system']);
        $this->assertStringContainsString('you never need to and never should ask which customer', $sent[0]['system']);
        $result = json_decode($sent[1]['messages'][2]['content'][0]['content'], true);
        $this->assertCount(1, $result['contracts']);
        $this->assertSame(7.5, $result['contracts'][0]['remaining_hours']);
        $this->assertStringNotContainsString('Other Customer', json_encode($sent));

        $row = AiInteraction::where('feature', 'portal_chat')->firstOrFail();
        $this->assertSame($this->portalUser->id, $row->portal_user_id);
        $this->assertNull($row->user_id);
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'ai_chat', 'action' => 'ai_portal_chat_answered', 'actor_name' => 'Alice Tan (portal)']);
    }

    public function test_a_job_order_id_belonging_to_the_other_customer_is_not_found(): void
    {
        $theirContract = Contract::factory()->create(['company_id' => $this->company->id, 'customer_id' => $this->theirs->id, 'status' => Contract::STATUS_ACTIVE]);
        $theirJobOrder = JobOrder::factory()->create(['company_id' => $this->company->id, 'customer_id' => $this->theirs->id, 'contract_id' => $theirContract->id]);

        $result = AiPortalTools::run('get_my_job_order', ['job_order_id' => $theirJobOrder->id], $this->portalUser, true);
        $this->assertSame(['error' => 'Job order not found.'], $result);
        $this->assertSame(['job_orders' => []], AiPortalTools::run('get_my_job_orders', ['contract_id' => $theirContract->id], $this->portalUser, true));
    }

    public function test_engineer_names_are_masked_under_the_same_redaction_setting(): void
    {
        $contract = Contract::factory()->create(['company_id' => $this->company->id, 'customer_id' => $this->mine->id, 'status' => Contract::STATUS_ACTIVE]);
        $jobOrder = JobOrder::factory()->create(['company_id' => $this->company->id, 'customer_id' => $this->mine->id, 'contract_id' => $contract->id]);
        $engineer = User::factory()->for($this->company)->create(['full_name' => 'Tan Wei Ming']);
        ServiceRecord::factory()->create(['company_id' => $this->company->id, 'job_order_id' => $jobOrder->id, 'employee_user_id' => $engineer->id]);

        $masked = AiPortalTools::run('get_my_job_order', ['job_order_id' => $jobOrder->id], $this->portalUser, true);
        $unmasked = AiPortalTools::run('get_my_job_order', ['job_order_id' => $jobOrder->id], $this->portalUser, false);
        $this->assertSame('[name]', $masked['service_records'][0]['engineer_name']);
        $this->assertSame('Tan Wei Ming', $unmasked['service_records'][0]['engineer_name']);
    }

    public function test_invoices_and_incidents_are_scoped_and_the_assistant_never_creates_one(): void
    {
        Invoice::create([
            'company_id' => $this->company->id, 'customer_id' => $this->mine->id, 'invoice_number' => 'INV-MINE-1',
            'invoice_type' => 'contract_annual', 'description' => 'My invoice', 'amount_sgd' => 500, 'tax_code' => 'ZR',
            'gst_rate' => 0, 'gst_amount_sgd' => 0, 'total_amount_sgd' => 500, 'amount_paid_sgd' => 0, 'status' => Invoice::STATUS_OUTSTANDING,
        ]);
        Invoice::create([
            'company_id' => $this->company->id, 'customer_id' => $this->theirs->id, 'invoice_number' => 'INV-THEIRS-1',
            'invoice_type' => 'contract_annual', 'description' => 'Their invoice', 'amount_sgd' => 9999, 'tax_code' => 'ZR',
            'gst_rate' => 0, 'gst_amount_sgd' => 0, 'total_amount_sgd' => 9999, 'amount_paid_sgd' => 0, 'status' => Invoice::STATUS_OUTSTANDING,
        ]);
        Incident::factory()->for($this->company)->create(['customer_id' => $this->mine->id, 'subject' => 'Mine']);
        Incident::factory()->for($this->company)->create(['customer_id' => $this->theirs->id, 'subject' => 'Theirs']);

        $invoices = AiPortalTools::run('get_my_invoices', [], $this->portalUser, true);
        $this->assertCount(1, $invoices['invoices']);
        $this->assertSame('INV-MINE-1', $invoices['invoices'][0]['invoice_number']);

        $incidents = AiPortalTools::run('get_my_incidents', [], $this->portalUser, true);
        $this->assertCount(1, $incidents['incidents']);
        $this->assertSame('Mine', $incidents['incidents'][0]['subject']);

        AiClient::fake([['text' => 'Please raise that from the Incidents tab -- I cannot create it for you.']]);
        $this->postJson('/api/portal/ai/chat', ['messages' => [['role' => 'user', 'content' => 'Please open an incident for a broken printer']]], $this->headers())->assertOk();
        $this->assertDatabaseCount('incidents', 2);
        $this->assertStringContainsString('you do not create it yourself', AiClient::requests()[0]['system']);
    }

    public function test_a_staff_token_is_refused_and_the_licence_gates_the_portal_too(): void
    {
        $owner = User::factory()->for($this->company)->create(['role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        $staffToken = $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])->json('access_token');
        $this->postJson('/api/portal/ai/chat', ['messages' => [['role' => 'user', 'content' => 'x']]], ['Authorization' => "Bearer {$staffToken}"])->assertStatus(401);
        $this->getJson('/api/portal/ai/persona', ['Authorization' => "Bearer {$staffToken}"])->assertStatus(401);

        // And a portal token is refused on the staff side (existing PORTAL-003 discipline, unaffected by this slice).
        $this->postJson('/api/ai/chat', ['messages' => [['role' => 'user', 'content' => 'x']]], $this->headers())->assertStatus(401);

        CompanyModule::where('company_id', $this->company->id)->where('module_key', 'ai_assistant')->update(['enabled' => false]);
        $this->getJson('/api/portal/ai/persona', $this->headers())->assertStatus(403);
        $this->postJson('/api/portal/ai/chat', ['messages' => [['role' => 'user', 'content' => 'x']]], $this->headers())->assertStatus(403);
    }

    public function test_refusal_is_recorded_and_a_malformed_history_is_rejected(): void
    {
        AiClient::fake([['__refused' => true, '__reason' => 'declined']]);
        $this->postJson('/api/portal/ai/chat', ['messages' => [['role' => 'user', 'content' => 'x']]], $this->headers())
            ->assertOk()->assertJsonPath('refused', true)->assertJsonPath('answer', 'declined');
        $this->assertSame('refused', AiInteraction::where('feature', 'portal_chat')->value('status'));

        $this->postJson('/api/portal/ai/chat', ['messages' => [['role' => 'assistant', 'content' => 'hi']]], $this->headers())->assertStatus(422);
        $this->assertCount(1, AiClient::requests());
    }
}
