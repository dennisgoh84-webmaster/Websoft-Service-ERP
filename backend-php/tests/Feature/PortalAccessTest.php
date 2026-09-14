<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Contact;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\PortalUser;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Staff-side Customer Helpdesk Portal access management (design §5,
 * §9 test plan items 1 and 5) -- the enable/disable/reset endpoints on
 * App\Http\Controllers\Api\CompanyIndividualController, plus the
 * PORTAL-004 archive cascade.
 *
 * RBAC/Module-Control coverage follows the same template as
 * CompanyIndividualTest.php, since these routes live under the same
 * `company_individual_management` module at the same access levels the
 * Python router uses.
 */
class PortalAccessTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'company_individual_management';

    private function ownerToken(Company $company): string
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);

        return $this->tokenFor($owner);
    }

    private function tokenFor(User $user): string
    {
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}"];
    }

    /** A non-owner staff member in $company, in a Group with $level on this module. */
    private function staffWithLevel(Company $company, string $level): User
    {
        ModuleCatalog::updateOrCreate(['key' => self::MODULE], ['name' => 'Customer Management', 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => self::MODULE],
            ['enabled' => true, 'license_type' => CompanyModule::INCLUDED],
        );
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::updateOrCreate(
            ['group_id' => $group->id, 'module_key' => self::MODULE],
            ['access_level' => $level],
        );
        $staff = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $staff->id, 'company_id' => $company->id, 'group_id' => $group->id]);

        return $staff;
    }

    /** @return array{0: CompanyIndividual, 1: Contact} */
    private function customerWithContact(Company $company, array $customerOverrides = [], ?string $contactEmail = 'alice@client.example'): array
    {
        $customer = CompanyIndividual::factory()->for($company)->create(array_merge(
            ['pdpa_consent_given' => true],
            $customerOverrides,
        ));
        $contact = Contact::create([
            'customer_id' => $customer->id,
            'name' => 'Alice Tan',
            'email' => $contactEmail,
        ]);

        return [$customer, $contact];
    }

    private function path(CompanyIndividual $customer, Contact $contact, string $suffix = ''): string
    {
        return "/api/company-individuals/{$customer->id}/contacts/{$contact->id}/portal-access{$suffix}";
    }

    // ---- §9.1 enabling access -------------------------------------------

    public function test_enable_portal_access_for_a_consented_contact_creates_a_working_login(): void
    {
        $company = Company::factory()->create();
        [$customer, $contact] = $this->customerWithContact($company);
        $token = $this->ownerToken($company);

        $enable = $this->postJson($this->path($customer, $contact), [], $this->headers($token));

        $enable->assertOk()->assertJson([
            'enabled' => true,
            'email' => 'alice@client.example',
            'must_change_password' => true,
            'locked' => false,
            // SMTP is unconfigured under `php artisan test`, so the
            // temporary password is shown on screen once instead of
            // being emailed (design §5's documented fallback).
            'invited_by_email' => false,
        ]);
        $tempPassword = $enable->json('temporary_password');
        $this->assertNotNull($tempPassword);
        $this->assertSame(10, strlen($tempPassword));

        $portalUser = PortalUser::where('contact_id', $contact->id)->firstOrFail();
        $this->assertSame($company->id, $portalUser->company_id);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'portal_user',
            'entity_id' => $portalUser->id,
            'action' => 'portal_access_enabled',
        ]);

        // The temporary password actually signs in to the portal.
        $this->postJson('/api/portal/auth/login', ['email' => $contact->email, 'password' => $tempPassword])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'must_change_password' => true]);
    }

    public function test_enable_is_refused_without_pdpa_consent(): void
    {
        $company = Company::factory()->create();
        [$customer, $contact] = $this->customerWithContact($company, ['pdpa_consent_given' => false]);
        $token = $this->ownerToken($company);

        $this->postJson($this->path($customer, $contact), [], $this->headers($token))
            ->assertStatus(422)
            ->assertJson(['detail' => 'PDPA consent has not been recorded for this customer -- record consent before enabling portal access.']);
        $this->assertDatabaseCount('portal_users', 0);
    }

    public function test_enable_is_refused_for_a_contact_with_no_email(): void
    {
        $company = Company::factory()->create();
        [$customer, $contact] = $this->customerWithContact($company, contactEmail: null);
        $token = $this->ownerToken($company);

        $this->postJson($this->path($customer, $contact), [], $this->headers($token))
            ->assertStatus(422)
            ->assertJson(['detail' => 'This contact has no email address -- add one before enabling portal access.']);
    }

    public function test_enable_is_refused_while_the_customer_is_archived(): void
    {
        $company = Company::factory()->create();
        [$customer, $contact] = $this->customerWithContact($company, ['is_archived' => true]);
        $token = $this->ownerToken($company);

        $this->postJson($this->path($customer, $contact), [], $this->headers($token))
            ->assertStatus(422)
            ->assertJson(['detail' => 'This customer is archived -- unarchive it before enabling portal access.']);
    }

    public function test_get_portal_access_reports_not_enabled_before_and_status_after(): void
    {
        $company = Company::factory()->create();
        [$customer, $contact] = $this->customerWithContact($company);
        $token = $this->ownerToken($company);

        $this->getJson($this->path($customer, $contact), $this->headers($token))
            ->assertOk()
            ->assertJson(['enabled' => false, 'email' => null, 'locked' => false]);

        $this->postJson($this->path($customer, $contact), [], $this->headers($token))->assertOk();

        $this->getJson($this->path($customer, $contact), $this->headers($token))
            ->assertOk()
            // The one-time temporary password is never replayed by the
            // plain GET -- only by the enable/reset action itself.
            ->assertJson(['enabled' => true, 'email' => $contact->email, 'temporary_password' => null]);
    }

    // ---- reset / disable / re-enable -------------------------------------

    public function test_reset_password_issues_a_new_temporary_password_and_clears_any_lock(): void
    {
        $company = Company::factory()->create();
        [$customer, $contact] = $this->customerWithContact($company);
        $token = $this->ownerToken($company);
        $first = $this->postJson($this->path($customer, $contact), [], $this->headers($token))->json('temporary_password');

        $portalUser = PortalUser::where('contact_id', $contact->id)->firstOrFail();
        $portalUser->locked_until = now()->addMinutes(15);
        $portalUser->failed_attempts = 3;
        $portalUser->save();

        $reset = $this->postJson($this->path($customer, $contact, '/reset-password'), [], $this->headers($token));
        $reset->assertOk()->assertJson(['enabled' => true, 'must_change_password' => true, 'locked' => false]);
        $second = $reset->json('temporary_password');
        $this->assertNotSame($first, $second);

        $fresh = $portalUser->fresh();
        $this->assertNull($fresh->locked_until);
        $this->assertSame(0, $fresh->failed_attempts);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'portal_user', 'entity_id' => $portalUser->id, 'action' => 'portal_password_reset_by_staff',
        ]);

        $this->postJson('/api/portal/auth/login', ['email' => $contact->email, 'password' => $second])->assertOk();
        $this->postJson('/api/portal/auth/login', ['email' => $contact->email, 'password' => $first])->assertStatus(401);
    }

    public function test_reset_password_on_a_contact_without_access_is_404(): void
    {
        $company = Company::factory()->create();
        [$customer, $contact] = $this->customerWithContact($company);
        $token = $this->ownerToken($company);

        $this->postJson($this->path($customer, $contact, '/reset-password'), [], $this->headers($token))
            ->assertStatus(404)
            ->assertJson(['detail' => 'This contact does not have portal access yet.']);
    }

    public function test_disable_then_re_enable_records_the_right_audit_actions(): void
    {
        $company = Company::factory()->create();
        [$customer, $contact] = $this->customerWithContact($company);
        $token = $this->ownerToken($company);
        $this->postJson($this->path($customer, $contact), [], $this->headers($token))->assertOk();
        $portalUser = PortalUser::where('contact_id', $contact->id)->firstOrFail();

        $this->postJson($this->path($customer, $contact, '/disable'), [], $this->headers($token))
            ->assertOk()->assertJson(['enabled' => false]);
        $this->assertFalse($portalUser->fresh()->is_active);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'portal_user', 'entity_id' => $portalUser->id, 'action' => 'portal_access_disabled',
        ]);

        // Re-enabling reuses the same row (one login per contact) and is
        // audited under its own action.
        $this->postJson($this->path($customer, $contact), [], $this->headers($token))->assertOk()->assertJson(['enabled' => true]);
        $this->assertDatabaseCount('portal_users', 1);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'portal_user', 'entity_id' => $portalUser->id, 'action' => 'portal_access_re_enabled',
        ]);
    }

    public function test_disable_on_a_contact_without_access_is_404(): void
    {
        $company = Company::factory()->create();
        [$customer, $contact] = $this->customerWithContact($company);
        $token = $this->ownerToken($company);

        $this->postJson($this->path($customer, $contact, '/disable'), [], $this->headers($token))
            ->assertStatus(404)
            ->assertJson(['detail' => 'This contact does not have portal access.']);
    }

    // ---- PORTAL-004 archive cascade --------------------------------------

    public function test_archiving_the_customer_disables_every_portal_login_under_it(): void
    {
        $company = Company::factory()->create();
        [$customer, $contact] = $this->customerWithContact($company);
        $second = Contact::create(['customer_id' => $customer->id, 'name' => 'Bob Lim', 'email' => 'bob@client.example']);
        $token = $this->ownerToken($company);
        $this->postJson($this->path($customer, $contact), [], $this->headers($token))->assertOk();
        $this->postJson($this->path($customer, $second), [], $this->headers($token))->assertOk();

        // A portal login under a DIFFERENT customer must be untouched.
        [$otherCustomer, $otherContact] = $this->customerWithContact($company, contactEmail: 'carol@other.example');
        $this->postJson($this->path($otherCustomer, $otherContact), [], $this->headers($token))->assertOk();

        $this->postJson("/api/company-individuals/{$customer->id}/archive", [], $this->headers($token))->assertOk();

        foreach ([$contact, $second] as $c) {
            $pu = PortalUser::where('contact_id', $c->id)->firstOrFail();
            $this->assertFalse($pu->is_active, 'PORTAL-004: archiving disables every portal login under the customer');
            $this->assertDatabaseHas('audit_log_entries', [
                'entity_type' => 'portal_user', 'entity_id' => $pu->id, 'action' => 'portal_access_disabled',
            ]);
        }
        $this->assertTrue(PortalUser::where('contact_id', $otherContact->id)->firstOrFail()->is_active);
    }

    // ---- RBAC / Module Control / multi-company ---------------------------

    public function test_a_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        [$customer, $contact] = $this->customerWithContact($company);
        $staff = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);

        $this->postJson($this->path($customer, $contact), [], $this->headers($this->tokenFor($staff)))->assertStatus(403);
    }

    public function test_a_view_only_group_can_read_status_but_not_enable(): void
    {
        $company = Company::factory()->create();
        [$customer, $contact] = $this->customerWithContact($company);
        $staff = $this->staffWithLevel($company, GroupModuleAuthority::VIEW);
        $headers = $this->headers($this->tokenFor($staff));

        $this->getJson($this->path($customer, $contact), $headers)->assertOk();
        $this->postJson($this->path($customer, $contact), [], $headers)->assertStatus(403);
        $this->postJson($this->path($customer, $contact, '/disable'), [], $headers)->assertStatus(403);
        $this->postJson($this->path($customer, $contact, '/reset-password'), [], $headers)->assertStatus(403);
    }

    public function test_a_disabled_module_is_denied_even_with_full_group_authority(): void
    {
        $company = Company::factory()->create();
        [$customer, $contact] = $this->customerWithContact($company);
        $staff = $this->staffWithLevel($company, GroupModuleAuthority::FULL);
        CompanyModule::where('company_id', $company->id)->where('module_key', self::MODULE)->update(['enabled' => false]);

        $this->postJson($this->path($customer, $contact), [], $this->headers($this->tokenFor($staff)))->assertStatus(403);
    }

    public function test_another_companys_customer_is_not_found(): void
    {
        $company = Company::factory()->create();
        $other = Company::factory()->create();
        [$customer, $contact] = $this->customerWithContact($other);
        $token = $this->ownerToken($company);

        $this->postJson($this->path($customer, $contact), [], $this->headers($token))
            ->assertStatus(404)
            ->assertJson(['detail' => 'Company / Individual not found']);
    }

    public function test_a_contact_belonging_to_another_customer_is_not_found(): void
    {
        $company = Company::factory()->create();
        [$customer] = $this->customerWithContact($company);
        [, $otherContact] = $this->customerWithContact($company, contactEmail: 'dave@other.example');
        $token = $this->ownerToken($company);

        $this->postJson($this->path($customer, $otherContact), [], $this->headers($token))
            ->assertStatus(404)
            ->assertJson(['detail' => 'Contact not found']);
    }

    public function test_a_portal_token_cannot_reach_the_staff_side_portal_access_routes(): void
    {
        $company = Company::factory()->create();
        [$customer, $contact] = $this->customerWithContact($company);
        $token = $this->ownerToken($company);
        $tempPassword = $this->postJson($this->path($customer, $contact), [], $this->headers($token))->json('temporary_password');
        $portalToken = $this->postJson('/api/portal/auth/login', [
            'email' => $contact->email, 'password' => $tempPassword,
        ])->json('portal_token');

        // The obvious privilege escalation -- a customer re-pointing
        // their own (or anyone's) portal access -- is refused by the
        // staff auth realm, not by any rule inside this controller.
        $this->getJson($this->path($customer, $contact), $this->headers($portalToken))->assertStatus(401);
        $this->postJson($this->path($customer, $contact, '/disable'), [], $this->headers($portalToken))->assertStatus(401);
    }
}
