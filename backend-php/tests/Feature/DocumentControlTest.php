<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\DocumentNumberFormat;
use App\Models\DocumentSequence;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\Numbering;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers App\Http\Controllers\Api\DocumentControlController --
 * converted from backend/app/routers/document_control.py. The
 * counter-adjustment and number-format halves of the Document Control
 * admin screen.
 *
 * Both writes are FULL-only and require a reason, so the RBAC block
 * checks EDIT is not enough (unlike every other module's write, which
 * only needs EDIT) as well as the usual no-Group / disabled-module /
 * cross-company cases.
 */
class DocumentControlTest extends TestCase
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

    private function tokenForLevel(Company $company, string $level, bool $moduleEnabled = true): string
    {
        ModuleCatalog::firstOrCreate(['key' => 'core_administration'], ['name' => 'Core / Administration', 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => 'core_administration'],
            ['enabled' => $moduleEnabled],
        );
        $group = Group::factory()->for($company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id, 'module_key' => 'core_administration', 'access_level' => $level,
        ]);
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $company->id, 'group_id' => $group->id]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        return $login->json('access_token');
    }

    public function test_listing_shows_each_counter_with_the_next_number_it_would_issue(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        // Consume two invoice numbers the normal way.
        Numbering::next($company->id, 'invoice');
        Numbering::next($company->id, 'invoice');
        $year = Carbon::today()->year;

        $this->getJson('/api/document-control', $this->headers($token))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.doc_kind', 'invoice')
            ->assertJsonPath('0.prefix', 'INV')
            ->assertJsonPath('0.year', $year)
            ->assertJsonPath('0.last_number', 2)
            ->assertJsonPath('0.next_number', "INV-{$year}-0003");
    }

    public function test_adjusting_a_counter_requires_a_reason_and_is_audit_logged(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        Numbering::next($company->id, 'invoice');
        $seq = DocumentSequence::where('company_id', $company->id)->firstOrFail();
        $year = Carbon::today()->year;

        // No reason -> rejected (Pydantic's reason: min_length=1).
        $this->patchJson("/api/document-control/{$seq->id}", ['last_number' => 50], $this->headers($token))
            ->assertStatus(422);

        $this->patchJson(
            "/api/document-control/{$seq->id}",
            ['last_number' => 50, 'reason' => 'Aligning with numbers already issued in Odoo'],
            $this->headers($token),
        )
            ->assertOk()
            ->assertJsonPath('last_number', 50)
            ->assertJsonPath('next_number', "INV-{$year}-0051");

        $this->assertSame(50, $seq->refresh()->last_number);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'document_sequence',
            'entity_id' => $seq->id,
            'action' => 'last_number_changed',
            'reason' => 'Aligning with numbers already issued in Odoo',
        ]);
    }

    public function test_formats_list_every_built_in_kind_as_a_non_custom_default(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $year = Carbon::today()->year;

        $response = $this->getJson('/api/document-control/formats', $this->headers($token));

        $response->assertOk();
        // A brand-new company has numbered nothing yet, but every kind
        // built into the app still shows up to customize ahead of time.
        $this->assertCount(count(Numbering::PREFIXES), $response->json());
        $kinds = array_column($response->json(), 'doc_kind');
        $this->assertSame($kinds, collect($kinds)->sort()->values()->all(), 'kinds are sorted');

        $invoice = collect($response->json())->firstWhere('doc_kind', 'invoice');
        $this->assertSame([
            'doc_kind' => 'invoice',
            'prefix' => 'INV',
            'number_length' => 4,
            'include_year' => true,
            'is_custom' => false,
            'example' => "INV-{$year}-0001",
        ], $invoice);
    }

    public function test_setting_a_format_changes_only_future_numbers(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $year = Carbon::today()->year;

        $alreadyIssued = Numbering::next($company->id, 'contract');
        $this->assertSame("CON-{$year}-0001", $alreadyIssued);

        $this->putJson(
            '/api/document-control/formats/contract',
            ['prefix' => 'WMC', 'number_length' => 6, 'include_year' => false, 'reason' => 'House style'],
            $this->headers($token),
        )
            ->assertOk()
            ->assertJson([
                'doc_kind' => 'contract',
                'prefix' => 'WMC',
                'number_length' => 6,
                'include_year' => false,
                'is_custom' => true,
                'example' => 'WMC-000001',
            ]);

        // The next number issued uses the new format; the one already
        // issued above keeps the text it was given (CLAUDE.md -- never
        // modify existing business records).
        $this->assertSame('WMC-000002', Numbering::next($company->id, 'contract'));
        $this->assertSame("CON-{$year}-0001", $alreadyIssued);

        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'document_number_format',
            'action' => 'updated',
            'reason' => 'House style',
        ]);
    }

    public function test_setting_a_format_twice_updates_the_same_row(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        foreach (['AAA', 'BBB'] as $prefix) {
            $this->putJson(
                '/api/document-control/formats/quotation',
                ['prefix' => $prefix, 'number_length' => 4, 'include_year' => true, 'reason' => 'r'],
                $this->headers($token),
            )->assertOk();
        }

        $this->assertSame(1, DocumentNumberFormat::where('company_id', $company->id)->count());
        $this->assertSame('BBB', DocumentNumberFormat::where('company_id', $company->id)->firstOrFail()->prefix);
    }

    public function test_an_invalid_prefix_or_length_is_rejected(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        // Lowercase / symbols / too long -- the confirmed 2026-09-11
        // rule is uppercase letters and digits only, 1-10 characters.
        foreach (['inv', 'IN-V', 'ABCDEFGHIJK', ''] as $prefix) {
            $this->putJson(
                '/api/document-control/formats/invoice',
                ['prefix' => $prefix, 'number_length' => 4, 'reason' => 'r'],
                $this->headers($token),
            )->assertStatus(422);
        }

        foreach ([0, 11] as $length) {
            $this->putJson(
                '/api/document-control/formats/invoice',
                ['prefix' => 'INV', 'number_length' => $length, 'reason' => 'r'],
                $this->headers($token),
            )->assertStatus(422);
        }
    }

    public function test_a_sequence_from_another_company_is_not_found(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $tokenA = $this->ownerToken($companyA);
        Numbering::next($companyB->id, 'invoice');
        $seqB = DocumentSequence::where('company_id', $companyB->id)->firstOrFail();

        $this->patchJson("/api/document-control/{$seqB->id}", ['last_number' => 1, 'reason' => 'r'], $this->headers($tokenA))
            ->assertStatus(404);
        // Company A's own listing never shows it either.
        $this->getJson('/api/document-control', $this->headers($tokenA))->assertOk()->assertJsonCount(0);
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/document-control', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_view_only_can_read_but_even_edit_cannot_write(): void
    {
        $company = Company::factory()->create();
        Numbering::next($company->id, 'invoice');
        $seq = DocumentSequence::where('company_id', $company->id)->firstOrFail();

        $viewToken = $this->tokenForLevel($company, GroupModuleAuthority::VIEW);
        $this->getJson('/api/document-control', $this->headers($viewToken))->assertOk();
        $this->getJson('/api/document-control/formats', $this->headers($viewToken))->assertOk();

        // Both writes are FULL-only in the Python router, so EDIT --
        // enough to write in every other module -- is still a 403 here.
        $editToken = $this->tokenForLevel($company, GroupModuleAuthority::EDIT);
        $this->patchJson("/api/document-control/{$seq->id}", ['last_number' => 5, 'reason' => 'r'], $this->headers($editToken))
            ->assertStatus(403);
        $this->putJson(
            '/api/document-control/formats/invoice',
            ['prefix' => 'ABC', 'number_length' => 4, 'reason' => 'r'],
            $this->headers($editToken),
        )->assertStatus(403);
    }

    public function test_disabled_module_is_denied_even_with_full_group_authority(): void
    {
        $company = Company::factory()->create();
        $token = $this->tokenForLevel($company, GroupModuleAuthority::FULL, moduleEnabled: false);

        $this->getJson('/api/document-control', $this->headers($token))->assertStatus(403);
    }
}
