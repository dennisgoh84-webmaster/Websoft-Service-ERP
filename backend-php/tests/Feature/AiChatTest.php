<?php

namespace Tests\Feature;

use App\Models\AiInteraction;
use App\Models\AiSetting;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contract;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\Incident;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\Ai\AiChat;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiTools;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AI Assistant slice 2 (docs/planned-work.md #12): the chat panel with
 * read-only tools that run as the signed-in user. The provider is
 * faked; these prove the loop, the permission gate, company scoping,
 * redaction and the record kept -- not the model's judgement.
 */
class AiChatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        AiClient::fake();
    }

    protected function tearDown(): void
    {
        AiClient::restore();
        parent::tearDown();
    }

    public function test_the_assistant_looks_things_up_with_tools_and_answers_in_kind(): void
    {
        [$company, $h] = $this->licensed(User::ROLE_OWNER);
        $acme = CompanyIndividual::factory()->for($company)->create(['name' => 'Acme Manufacturing Pte Ltd', 'is_customer' => true, 'contact_person' => 'Tan Wei Ming', 'phone' => '+65 6123 4567']);
        $contract = Contract::factory()->for($company)->create(['customer_id' => $acme->id, 'status' => Contract::STATUS_ACTIVE, 'contracted_minutes' => 600, 'consumed_minutes' => 90]);

        AiClient::fake([
            ['tool_calls' => [['name' => 'find_customers', 'input' => ['query' => 'Acme']]], '__input_tokens' => 800, '__output_tokens' => 40],
            ['tool_calls' => [['name' => 'get_customer_contracts', 'input' => ['customer_id' => $acme->id]]], '__input_tokens' => 900, '__output_tokens' => 50],
            ['text' => 'Acme 还剩 8.5 小时（合同 '.$contract->contract_number.'）。', '__input_tokens' => 1000, '__output_tokens' => 60],
        ]);

        $res = $this->postJson('/api/ai/chat', [
            'messages' => [['role' => 'user', 'content' => 'Acme 还剩多少小时？']],
            'context' => ['type' => 'customer', 'id' => $acme->id],
        ], $h)->assertOk();

        $res->assertJsonPath('answer', 'Acme 还剩 8.5 小时（合同 '.$contract->contract_number.'）。')
            ->assertJsonPath('refused', false)
            ->assertJsonPath('tools_used.0.name', 'find_customers')
            ->assertJsonPath('tools_used.1.name', 'get_customer_contracts')
            ->assertJsonPath('input_tokens', 2700)
            ->assertJsonPath('output_tokens', 150);

        $sent = AiClient::requests();
        $this->assertCount(3, $sent);
        // The language rule and the page context are in the system prompt; the tools are offered.
        $this->assertStringContainsString('reply in the language the person wrote in', $sent[0]['system']);
        $this->assertStringContainsString('Bahasa Melayu', $sent[0]['system']);
        $this->assertStringContainsString('Company / Individual "Acme Manufacturing Pte Ltd"', $sent[0]['system']);
        $this->assertStringContainsString('Never add, subtract or convert', $sent[0]['system']);
        $this->assertContains('get_customer_receivables', $sent[0]['tools']);
        // The second request carries the first tool's result, the third the contract figures the service computed.
        $found = $this->lastToolResult($sent[1]);
        $this->assertSame('Acme Manufacturing Pte Ltd', $found['customers'][0]['name']);
        $contracts = $this->lastToolResult($sent[2]);
        $this->assertSame(8.5, $contracts['contracts'][0]['remaining_hours']);
        $this->assertSame(1.5, $contracts['contracts'][0]['consumed_hours']);
        // Redaction (on by default) kept the contact person and phone out of the tool result.
        $this->assertArrayNotHasKey('contact_person', $found['customers'][0]);
        $this->assertArrayNotHasKey('phone', $found['customers'][0]);

        $row = AiInteraction::where('feature', 'chat')->firstOrFail();
        $this->assertSame('ok', $row->status);
        $this->assertSame(2700, $row->input_tokens);
        $this->assertSame('customer', $row->entity_type);
        $this->assertSame($acme->id, $row->entity_id);
        $this->assertSame(['find_customers', 'get_customer_contracts'], array_column($row->response['tools_used'], 'name'));
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'ai_chat', 'entity_id' => $row->id, 'action' => 'ai_chat_answered']);
    }

    public function test_tools_run_as_the_user_so_a_support_engineer_cannot_see_receivables(): void
    {
        [$company, $h, $engineer] = $this->licensed(User::ROLE_SUPPORT_ENGINEER, ['service_operations', 'service_records']);
        $acme = CompanyIndividual::factory()->for($company)->create(['name' => 'Acme Manufacturing Pte Ltd', 'is_customer' => true]);
        Invoice::create([
            'company_id' => $company->id, 'customer_id' => $acme->id, 'invoice_number' => 'INV-TEST-1', 'invoice_type' => 'contract_annual',
            'description' => 'Annual support', 'amount_sgd' => 4321.00, 'tax_code' => 'ZR', 'gst_rate' => 0, 'gst_amount_sgd' => 0,
            'total_amount_sgd' => 4321.00, 'amount_paid_sgd' => 0, 'status' => Invoice::STATUS_OUTSTANDING, 'due_date' => now()->subDays(40)->toDateString(),
        ]);

        AiClient::fake([
            ['tool_calls' => [['name' => 'get_customer_receivables', 'input' => ['customer_id' => $acme->id]]]],
            ['text' => 'You do not have access to Accounts Receivable.'],
        ]);
        $this->postJson('/api/ai/chat', ['messages' => [['role' => 'user', 'content' => 'How much does Acme owe?']]], $h)
            ->assertOk()->assertJsonPath('tools_used.0.summary', "Not permitted: the person asking does not have access to the 'accounts_receivable' module, so this information cannot be shown to them.");

        $toolResult = $this->lastToolResult(AiClient::requests()[1]);
        $this->assertStringContainsString('Not permitted', $toolResult['error']);
        $this->assertStringNotContainsString('4321', json_encode($toolResult), 'no invoice figure reached the model');

        // The owner, who has the module, gets the figure -- computed by the AR service.
        [, $ownerH] = $this->licensed(User::ROLE_OWNER, [], $company);
        AiClient::fake([
            ['tool_calls' => [['name' => 'get_customer_receivables', 'input' => ['customer_id' => $acme->id]]]],
            ['text' => 'Acme owes SGD 4,321.00.'],
        ]);
        $this->postJson('/api/ai/chat', ['messages' => [['role' => 'user', 'content' => 'How much does Acme owe?']]], $ownerH)->assertOk();
        $statement = $this->lastToolResult(AiClient::requests()[1]);
        $this->assertEquals(4321, $statement['total_outstanding_sgd']);
        $this->assertGreaterThanOrEqual(39, $statement['lines'][0]['days_overdue'], 'overdue days come from the AR service');
    }

    public function test_tools_are_scoped_to_the_users_company(): void
    {
        [$company, $h, $owner] = $this->licensed(User::ROLE_OWNER);
        $other = Company::factory()->create();
        $theirs = CompanyIndividual::factory()->for($other)->create(['name' => 'Their Customer', 'is_customer' => true]);
        Contract::factory()->for($other)->create(['customer_id' => $theirs->id, 'status' => Contract::STATUS_ACTIVE]);

        $this->assertSame(['error' => 'Customer not found.'], AiTools::run('get_customer_contracts', ['customer_id' => $theirs->id], $owner, true));
        $this->assertSame(['customers' => []], AiTools::run('find_customers', ['query' => 'Their'], $owner, true));
        $this->assertNull(AiChat::describeContext($owner, ['type' => 'customer', 'id' => $theirs->id]));
        $this->assertSame(['error' => 'Unknown tool nope.'], AiTools::run('nope', [], $owner, true));
    }

    public function test_redaction_off_lets_contact_details_through(): void
    {
        [$company, $h, $owner] = $this->licensed(User::ROLE_OWNER);
        AiSetting::current()->fill(['redact_personal_data' => false])->save();
        CompanyIndividual::factory()->for($company)->create(['name' => 'Acme Manufacturing Pte Ltd', 'is_customer' => true, 'contact_person' => 'Tan Wei Ming']);
        Incident::factory()->for($company)->create(['subject' => 'Call from Tan Wei Ming', 'sender_name' => 'Tan Wei Ming', 'sender_email' => 'wm@acme.com.sg']);

        $on = AiTools::run('list_incidents', [], $owner, true);
        $off = AiTools::run('list_incidents', [], $owner, false);
        $this->assertSame('Call from [name]', $on['incidents'][0]['subject']);
        $this->assertSame('[name]', $on['incidents'][0]['sender_name']);
        $this->assertSame('acme.com.sg', $on['incidents'][0]['sender_email_domain']);
        $this->assertSame('Call from Tan Wei Ming', $off['incidents'][0]['subject']);
        $this->assertSame('Tan Wei Ming', AiTools::run('find_customers', ['query' => 'Acme'], $owner, false)['customers'][0]['contact_person']);
    }

    public function test_the_page_context_is_described_and_job_orders_can_be_filtered_to_mine(): void
    {
        [$company, $h, $owner] = $this->licensed(User::ROLE_OWNER);
        $acme = CompanyIndividual::factory()->for($company)->create(['name' => 'Acme', 'is_customer' => true]);
        $contract = Contract::factory()->for($company)->create(['customer_id' => $acme->id, 'status' => Contract::STATUS_ACTIVE]);
        $mine = JobOrder::factory()->for($company)->create(['customer_id' => $acme->id, 'contract_id' => $contract->id, 'assigned_to_user_id' => $owner->id, 'status' => JobOrder::STATUS_ASSIGNED, 'subject' => 'Mine']);
        JobOrder::factory()->for($company)->create(['customer_id' => $acme->id, 'contract_id' => $contract->id, 'subject' => 'Someone else']);

        $this->assertStringStartsWith("Job Order {$mine->job_order_number} (id {$mine->id}), subject \"Mine\"", AiChat::describeContext($owner, ['type' => 'job_order', 'id' => $mine->id]));
        $this->assertSame('the Service Records list', AiChat::describeContext($owner, ['type' => 'service_records']));
        $this->assertNull(AiChat::describeContext($owner, ['type' => 'bogus', 'id' => $mine->id]));

        $rows = AiTools::run('list_job_orders', ['assigned_to_me' => true], $owner, true)['job_orders'];
        $this->assertCount(1, $rows);
        $this->assertSame('Mine', $rows[0]['subject']);
        $this->assertSame($owner->full_name, $rows[0]['assigned_to']);
        $this->assertCount(2, AiTools::run('list_job_orders', ['customer_id' => $acme->id], $owner, true)['job_orders']);
    }

    public function test_a_malformed_history_is_refused_and_a_runaway_tool_loop_is_stopped(): void
    {
        [$company, $h] = $this->licensed(User::ROLE_OWNER);

        $this->postJson('/api/ai/chat', ['messages' => [['role' => 'assistant', 'content' => 'hi']]], $h)->assertStatus(422);
        $this->postJson('/api/ai/chat', ['messages' => [['role' => 'user', 'content' => str_repeat('x', 6001)]]], $h)->assertStatus(422);
        $this->postJson('/api/ai/chat', ['messages' => []], $h)->assertStatus(422);
        $this->assertCount(0, AiClient::requests());

        // Eight rounds of tool calls, then a ninth: the loop stops and says so.
        $forever = array_fill(0, 10, ['tool_calls' => [['name' => 'get_ar_aging_summary', 'input' => []]]]);
        AiClient::fake($forever);
        $this->postJson('/api/ai/chat', ['messages' => [['role' => 'user', 'content' => 'loop']]], $h)
            ->assertOk()->assertJsonPath('answer', 'I looked up as much as I am allowed to in one go and could not finish -- please ask a narrower question.');
        $this->assertCount(AiChat::MAX_TOOL_ROUNDS + 1, AiClient::requests());
        $this->assertSame(1, AiInteraction::where('feature', 'chat')->count());
    }

    public function test_refusal_and_error_are_recorded_and_the_licence_gates_chat(): void
    {
        [$company, $h] = $this->licensed(User::ROLE_OWNER);
        AiClient::fake([['__refused' => true, '__reason' => 'declined']]);
        $this->postJson('/api/ai/chat', ['messages' => [['role' => 'user', 'content' => 'x']]], $h)->assertOk()->assertJsonPath('refused', true)->assertJsonPath('answer', 'declined');
        AiClient::fake([['__error' => 'Could not reach the model provider: boom']]);
        $this->postJson('/api/ai/chat', ['messages' => [['role' => 'user', 'content' => 'x']]], $h)->assertStatus(502);
        $this->assertSame(['refused', 'error'], AiInteraction::where('feature', 'chat')->orderBy('created_at')->pluck('status')->all());

        CompanyModule::where('company_id', $company->id)->where('module_key', 'ai_assistant')->update(['enabled' => false]);
        $this->postJson('/api/ai/chat', ['messages' => [['role' => 'user', 'content' => 'x']]], $h)->assertStatus(403);
        $this->getJson('/api/ai/persona', $h)->assertStatus(403);
    }

    public function test_the_assistant_has_a_name_and_a_face(): void
    {
        [$company, $h] = $this->licensed(User::ROLE_OWNER);
        $this->getJson('/api/ai/persona', $h)->assertOk()->assertJsonPath('name', 'Websoft AI')->assertJsonPath('avatar', null);

        $png = 'data:image/png;base64,'.base64_encode(random_bytes(64));
        $this->patchJson('/api/ai/settings', ['assistant_name' => 'Sofia', 'assistant_avatar' => $png], $h)
            ->assertOk()->assertJsonPath('assistant_name', 'Sofia')->assertJsonPath('assistant_avatar', $png);
        $this->getJson('/api/ai/persona', $h)->assertOk()->assertJsonPath('name', 'Sofia')->assertJsonPath('avatar', $png);
        $this->patchJson('/api/ai/settings', ['assistant_avatar' => 'data:text/html;base64,AAAA'], $h)->assertStatus(422);
        $this->patchJson('/api/ai/settings', ['assistant_avatar' => 'data:image/png;base64,'.str_repeat('A', 400_001)], $h)->assertStatus(422);
        $this->patchJson('/api/ai/settings', ['assistant_avatar' => null], $h)->assertOk()->assertJsonPath('assistant_avatar', null);

        // The name reaches the model.
        AiClient::fake([['text' => 'Hello']]);
        $this->postJson('/api/ai/chat', ['messages' => [['role' => 'user', 'content' => 'hi']]], $h)->assertOk();
        $this->assertStringContainsString('You are Sofia,', AiClient::requests()[0]['system']);
    }

    // ---- helpers ----------------------------------------------------

    /** The decoded content of the last tool_result in a recorded request's messages. */
    private function lastToolResult(array $request): array
    {
        $last = end($request['messages']);
        $this->assertSame('user', $last['role']);
        $this->assertSame('tool_result', $last['content'][0]['type']);

        return json_decode($last['content'][0]['content'], true);
    }

    /**
     * A signed-in user of a company licensed for the AI Assistant. A
     * non-owner gets FULL group access to the given modules only.
     *
     * @return array{0: Company, 1: array<string, string>, 2: User}
     */
    private function licensed(string $role, array $modules = [], ?Company $company = null): array
    {
        $company ??= Company::factory()->create();
        $all = ['ai_assistant', 'core_administration', 'service_operations', 'service_records', 'service_contracts', 'company_individual_management', 'accounts_receivable'];
        foreach ($all as $key) {
            ModuleCatalog::firstOrCreate(['key' => $key], ['name' => $key, 'is_built' => true]);
            CompanyModule::updateOrCreate(['company_id' => $company->id, 'module_key' => $key], ['enabled' => true, 'license_type' => CompanyModule::INCLUDED]);
        }
        $user = User::factory()->for($company)->create(['role' => $role, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        if ($role !== User::ROLE_OWNER) {
            $group = Group::factory()->for($company)->create();
            foreach (array_merge(['ai_assistant'], $modules) as $key) {
                GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => $key, 'access_level' => GroupModuleAuthority::FULL]);
            }
            UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        }
        $token = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])->json('access_token');

        return [$company, ['Authorization' => "Bearer {$token}"], $user];
    }
}
