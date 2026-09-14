<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\Incident;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API-level coverage of App\Http\Controllers\Api\IncidentController --
 * RBAC/multi-company/audit, per docs/php-conversion-plan.md's "after
 * converting each module" checklist. Business-rule logic (routing,
 * status transitions) is covered separately in IncidentServiceTest.php.
 */
class IncidentTest extends TestCase
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

    public function test_owner_can_create_and_list_incidents(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $token = $this->ownerToken($company);

        $create = $this->postJson('/api/incidents', [
            'customer_id' => $customer->id,
            'source' => 'phone',
            'subject' => 'Printer jammed',
            'sender_name' => 'Alice Tan',
            'sender_phone' => '+65 1234 5678',
        ], $this->headers($token));

        $create->assertOk()->assertJson(['status' => 'open', 'subject' => 'Printer jammed']);
        $this->assertStringStartsWith('INC-', $create->json('incident_number'));

        $this->getJson('/api/incidents', $this->headers($token))->assertOk()->assertJsonCount(1);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'incident', 'entity_id' => $create->json('id'), 'action' => 'created',
        ]);
    }

    public function test_create_without_a_customer_auto_matches_by_sender_email(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        Contact::create(['customer_id' => $customer->id, 'name' => 'Jane', 'email' => 'jane@example.com']);
        $token = $this->ownerToken($company);

        $create = $this->postJson('/api/incidents', [
            'source' => 'email',
            'subject' => 'Cannot log in',
            'sender_email' => 'JANE@example.com',
        ], $this->headers($token));

        $create->assertOk();
        $this->assertSame($customer->id, $create->json('customer_id'));
    }

    public function test_create_with_no_matching_email_leaves_customer_unset(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $create = $this->postJson('/api/incidents', [
            'source' => 'email',
            'subject' => 'Unknown sender',
            'sender_email' => 'nobody@example.com',
        ], $this->headers($token));

        $create->assertOk();
        $this->assertNull($create->json('customer_id'));
    }

    public function test_set_customer_then_convert_to_quotation(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $token = $this->ownerToken($company);
        $incident = Incident::factory()->for($company)->create(['subject' => 'Wants a quote for 3 laptops']);

        $this->patchJson("/api/incidents/{$incident->id}/customer", ['customer_id' => $customer->id], $this->headers($token))
            ->assertOk()->assertJson(['customer_id' => $customer->id]);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'incident', 'entity_id' => $incident->id, 'action' => 'customer_set',
        ]);

        $convert = $this->postJson(
            "/api/incidents/{$incident->id}/convert-to-quotation",
            ['quotation_date' => '2026-09-14'],
            $this->headers($token),
        );

        $convert->assertOk()->assertJson(['status' => 'draft', 'customer_id' => $customer->id]);
        $this->assertCount(1, $convert->json('lines'));
        $this->assertSame('converted', Incident::find($incident->id)->status);
    }

    public function test_convert_to_quotation_without_a_customer_returns_422(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $incident = Incident::factory()->for($company)->create();

        $response = $this->postJson(
            "/api/incidents/{$incident->id}/convert-to-quotation",
            ['quotation_date' => '2026-09-14'],
            $this->headers($token),
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('Set a Company/Individual', $response->json('detail'));
    }

    public function test_convert_to_job_order_against_a_valid_contract(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create([
            'customer_id' => $customer->id, 'status' => Contract::STATUS_ACTIVE,
        ]);
        $token = $this->ownerToken($company);
        $incident = Incident::factory()->for($company)->create([
            'customer_id' => $customer->id, 'subject' => 'Server room AC not cooling',
        ]);

        $response = $this->postJson(
            "/api/incidents/{$incident->id}/convert-to-job-order",
            ['contract_id' => $contract->id, 'priority' => 'high'],
            $this->headers($token),
        );

        $response->assertOk()->assertJson([
            'customer_id' => $customer->id, 'contract_id' => $contract->id,
            'subject' => 'Server room AC not cooling', 'priority' => 'high', 'job_order_type' => 'support',
        ]);
        $this->assertSame('converted', Incident::find($incident->id)->status);
    }

    public function test_convert_to_job_order_against_an_invalid_contract_returns_422(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $contract = Contract::factory()->for($company)->create([
            'customer_id' => $customer->id, 'status' => Contract::STATUS_EXPIRED,
        ]);
        $token = $this->ownerToken($company);
        $incident = Incident::factory()->for($company)->create(['customer_id' => $customer->id]);

        $response = $this->postJson(
            "/api/incidents/{$incident->id}/convert-to-job-order",
            ['contract_id' => $contract->id],
            $this->headers($token),
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('not a valid contract', $response->json('detail'));
    }

    public function test_set_callback_then_close_are_status_transitions(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $staff = User::factory()->for($company)->create();
        $incident = Incident::factory()->for($company)->create();

        $callback = $this->postJson(
            "/api/incidents/{$incident->id}/callback",
            ['assigned_to_user_id' => $staff->id],
            $this->headers($token),
        );
        $callback->assertOk()->assertJson(['status' => 'pending_callback', 'assigned_to_user_id' => $staff->id]);

        // Setting a callback twice is rejected -- close_incident() has
        // no such "must be open" guard (only CONVERTED/CLOSED block
        // it), so a pending_callback Incident can still be closed.
        $this->postJson(
            "/api/incidents/{$incident->id}/callback",
            ['assigned_to_user_id' => $staff->id],
            $this->headers($token),
        )->assertStatus(422);

        $this->postJson("/api/incidents/{$incident->id}/close", ['reason' => 'done'], $this->headers($token))
            ->assertOk()->assertJson(['status' => 'closed']);
    }

    public function test_close_an_open_incident(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $incident = Incident::factory()->for($company)->create();

        $response = $this->postJson("/api/incidents/{$incident->id}/close", ['reason' => 'Resolved over the phone'], $this->headers($token));

        $response->assertOk()->assertJson(['status' => 'closed', 'close_reason' => 'Resolved over the phone']);
        $this->assertNotNull($response->json('closed_at'));
    }

    public function test_from_email_creates_a_plain_incident(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $response = $this->postJson('/api/incidents/from-email', [
            'sender_name' => 'Bob',
            'sender_email' => 'bob@example.com',
            'subject' => 'Need help resetting password',
            'body' => 'Please reset my password.',
        ], $this->headers($token));

        $response->assertOk()->assertJson(['source' => 'email', 'subject' => 'Need help resetting password']);
    }

    public function test_from_email_convert_to_job_order_falls_back_to_a_plain_incident_when_no_customer_matches(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $response = $this->postJson('/api/incidents/from-email/convert-to-job-order', [
            'sender_email' => 'unknown@example.com',
            'subject' => 'Server is down',
        ], $this->headers($token));

        $response->assertOk()->assertJson(['job_order_created' => false]);
        $this->assertStringContainsString('No Company/Individual matches', $response->json('fallback_reason'));
        $this->assertSame('open', $response->json('incident.status'));
    }

    public function test_from_email_convert_to_job_order_falls_back_when_no_active_contract(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        Contact::create(['customer_id' => $customer->id, 'name' => 'Carl', 'email' => 'carl@example.com']);
        $token = $this->ownerToken($company);

        $response = $this->postJson('/api/incidents/from-email/convert-to-job-order', [
            'sender_email' => 'carl@example.com',
            'subject' => 'Server is down',
        ], $this->headers($token));

        $response->assertOk()->assertJson(['job_order_created' => false]);
        $this->assertStringContainsString('No active contract', $response->json('fallback_reason'));
    }

    public function test_from_email_convert_to_job_order_succeeds_with_a_valid_contract(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        Contact::create(['customer_id' => $customer->id, 'name' => 'Dana', 'email' => 'dana@example.com']);
        Contract::factory()->for($company)->create(['customer_id' => $customer->id, 'status' => Contract::STATUS_ACTIVE]);
        $token = $this->ownerToken($company);

        $response = $this->postJson('/api/incidents/from-email/convert-to-job-order', [
            'sender_email' => 'dana@example.com',
            'subject' => 'Server is down',
        ], $this->headers($token));

        $response->assertOk()->assertJson(['job_order_created' => true, 'fallback_reason' => null]);
        $this->assertSame('converted', $response->json('incident.status'));
        $this->assertNotNull($response->json('incident.converted_job_order_id'));
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/incidents', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_view_only_group_cannot_create(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'service_operations'], ['name' => 'Service Operations', 'is_built' => true]);
        CompanyModule::create(['company_id' => $company->id, 'module_key' => 'service_operations', 'enabled' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'service_operations', 'access_level' => GroupModuleAuthority::VIEW]);
        $staff = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);
        $token = $login->json('access_token');

        $this->getJson('/api/incidents', $this->headers($token))->assertOk();
        $this->postJson('/api/incidents', ['subject' => 'x'], $this->headers($token))->assertStatus(403);
    }

    public function test_disabled_module_is_denied_even_with_full_group_authority(): void
    {
        $company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => 'service_operations'], ['name' => 'Service Operations', 'is_built' => true]);
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'service_operations', 'access_level' => GroupModuleAuthority::FULL]);
        // No CompanyModule row at all -- Module Control fails closed.
        $staff = User::factory()->for($company)->create(['hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $staff->email, 'password' => 'demo1234']);

        $this->getJson('/api/incidents', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_incident_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $token = $this->ownerToken($companyA);
        $foreignIncident = Incident::factory()->for($companyB)->create();

        $this->getJson("/api/incidents/{$foreignIncident->id}", $this->headers($token))->assertStatus(404);
    }
}
