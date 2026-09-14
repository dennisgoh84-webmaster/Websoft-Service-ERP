<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\DocumentAttachment;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\DocumentService;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Http\Controllers\Api\DocumentController -- the generic
 * eDocument attachment + eSignature endpoints converted from
 * backend/app/routers/documents.py.
 *
 * Follows tests/Feature/CompanyIndividualTest.php's RBAC template
 * (happy path, no Group, VIEW-only on a write, Module Control
 * disabled, another company's record) plus the file-upload specifics:
 * a real multipart upload, the 20MB rejection, and the soft-delete
 * posture (the row is flagged, the file is never removed).
 */
class DocumentTest extends TestCase
{
    use RefreshDatabase;

    private string $uploadsDir;

    protected function setUp(): void
    {
        parent::setUp();
        // A throwaway uploads root per test, so a real file genuinely
        // lands on disk (the point of this module) without touching the
        // dev storage directory.
        $this->uploadsDir = sys_get_temp_dir().'/websoft-doc-tests-'.Str::uuid();
        config(['websoft.uploads_dir' => $this->uploadsDir]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->uploadsDir)) {
            exec('rm -rf '.escapeshellarg($this->uploadsDir));
        }
        parent::tearDown();
    }

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

    /** A non-owner whose Group has the given access level on core_administration. */
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

    public function test_upload_list_download_and_soft_delete_an_attachment(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $entityId = (string) Str::uuid();

        $upload = $this->post(
            "/api/documents/invoice/{$entityId}/attachments",
            ['file' => UploadedFile::fake()->createWithContent('Signed PO.pdf', 'hello world'), 'description' => 'Signed copy'],
            $this->headers($token),
        );

        $upload->assertStatus(201)->assertJson([
            'entity_type' => 'invoice',
            'entity_id' => $entityId,
            'original_filename' => 'Signed PO.pdf',
            'file_size_bytes' => 11,
            'description' => 'Signed copy',
        ]);
        $attachmentId = $upload->json('id');
        // The drawn/stored file name is never exposed -- Python's
        // DocumentAttachmentOut omits stored_filename too.
        $upload->assertJsonMissingPath('stored_filename');

        // The file really is on disk, under the Python layout.
        $attachment = DocumentAttachment::findOrFail($attachmentId);
        $path = DocumentService::attachmentFilePath($attachment);
        $this->assertNotNull($path);
        $this->assertSame('hello world', file_get_contents($path));
        $this->assertStringContainsString("/{$company->id}/docs/invoice/{$entityId}/", $path);
        $this->assertSame("{$attachmentId}.pdf", $attachment->stored_filename);

        $this->getJson("/api/documents/invoice/{$entityId}/attachments", $this->headers($token))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $attachmentId);

        $download = $this->get("/api/documents/invoice/{$entityId}/attachments/{$attachmentId}/download", $this->headers($token));
        $download->assertOk()->assertDownload('Signed PO.pdf');
        $this->assertSame('hello world', file_get_contents($download->baseResponse->getFile()->getPathname()));

        $this->delete("/api/documents/invoice/{$entityId}/attachments/{$attachmentId}", [], $this->headers($token))
            ->assertStatus(204);

        // Soft-delete only: the row is flagged, never removed, and
        // neither is the file (CLAUDE.md -- never permanently delete).
        $this->assertTrue(DocumentAttachment::findOrFail($attachmentId)->is_deleted);
        $this->assertFileExists($path);
        $this->getJson("/api/documents/invoice/{$entityId}/attachments", $this->headers($token))
            ->assertOk()
            ->assertJsonCount(0);
        // An already-deleted attachment is gone as far as the API is
        // concerned, same as the Python router's own guard.
        $this->delete("/api/documents/invoice/{$entityId}/attachments/{$attachmentId}", [], $this->headers($token))
            ->assertStatus(404);
    }

    public function test_upload_records_an_audit_entry_under_the_parent_entity_type(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $entityId = (string) Str::uuid();

        $this->post(
            "/api/documents/job_order/{$entityId}/attachments",
            ['file' => UploadedFile::fake()->createWithContent('site-photo.PNG', 'x')],
            $this->headers($token),
        )->assertStatus(201);

        // Same entity_type/action strings as the Python call site.
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'job_order',
            'entity_id' => $entityId,
            'action' => 'document_attachment_upload',
        ]);
    }

    public function test_a_file_over_20mb_is_rejected(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $entityId = (string) Str::uuid();

        // 20MB + 1 byte -- one byte past DocumentService::MAX_FILE_SIZE.
        $oversize = UploadedFile::fake()->createWithContent('big.bin', str_repeat('a', DocumentService::MAX_FILE_SIZE + 1));

        $this->post("/api/documents/invoice/{$entityId}/attachments", ['file' => $oversize], $this->headers($token))
            ->assertStatus(422)
            ->assertJsonPath('detail', 'File exceeds 20MB limit (20,971,521 bytes).');

        $this->assertSame(0, DocumentAttachment::count());
    }

    public function test_any_file_type_is_accepted_and_an_extensionless_name_still_stores(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $entityId = (string) Str::uuid();

        // Confirmed 2026-09-12: any file type, no allow-list.
        $response = $this->post(
            "/api/documents/contract/{$entityId}/attachments",
            ['file' => UploadedFile::fake()->createWithContent('Makefile', 'all:')],
            $this->headers($token),
        );

        $response->assertStatus(201);
        $attachment = DocumentAttachment::findOrFail($response->json('id'));
        $this->assertSame($attachment->id, $attachment->stored_filename);
        $this->assertNotNull(DocumentService::attachmentFilePath($attachment));
    }

    public function test_an_unknown_entity_type_is_rejected(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->getJson('/api/documents/not_a_document/'.Str::uuid().'/attachments', $this->headers($token))
            ->assertStatus(422);
    }

    public function test_add_and_list_signatures(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $entityId = (string) Str::uuid();

        $created = $this->postJson(
            "/api/documents/payment_voucher/{$entityId}/signatures",
            [
                'entity_type' => 'payment_voucher',
                'entity_id' => $entityId,
                'signer_name' => 'Dennis Goh',
                'signature_data_uri' => 'data:image/png;base64,iVBORw0KGgo=',
                'role_label' => 'Authorized signatory',
            ],
            $this->headers($token),
        );

        $created->assertStatus(201)->assertJson([
            'entity_type' => 'payment_voucher',
            'signer_name' => 'Dennis Goh',
            'role_label' => 'Authorized signatory',
        ]);
        // The drawn image itself is never returned -- Python's
        // DocumentSignatureOut leaves signature_data_uri out.
        $created->assertJsonMissingPath('signature_data_uri');

        $this->getJson("/api/documents/payment_voucher/{$entityId}/signatures", $this->headers($token))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.signer_name', 'Dennis Goh');

        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'payment_voucher',
            'entity_id' => $entityId,
            'action' => 'document_signature_add',
        ]);
    }

    public function test_a_signature_requires_a_name_and_a_drawn_image(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->postJson(
            '/api/documents/quotation/'.Str::uuid().'/signatures',
            ['signer_name' => '', 'signature_data_uri' => ''],
            $this->headers($token),
        )->assertStatus(422);
    }

    public function test_attachments_never_leak_across_companies(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $tokenA = $this->ownerToken($companyA);
        $tokenB = $this->ownerToken($companyB);
        $entityId = (string) Str::uuid();

        $upload = $this->post(
            "/api/documents/invoice/{$entityId}/attachments",
            ['file' => UploadedFile::fake()->createWithContent('a.txt', 'secret')],
            $this->headers($tokenA),
        );
        $attachmentId = $upload->json('id');

        // Company B, on the very same entity id, sees nothing and
        // cannot reach the file.
        $this->getJson("/api/documents/invoice/{$entityId}/attachments", $this->headers($tokenB))
            ->assertOk()
            ->assertJsonCount(0);
        $this->get("/api/documents/invoice/{$entityId}/attachments/{$attachmentId}/download", $this->headers($tokenB))
            ->assertStatus(404);
        $this->delete("/api/documents/invoice/{$entityId}/attachments/{$attachmentId}", [], $this->headers($tokenB))
            ->assertStatus(404);
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/documents/invoice/'.Str::uuid().'/attachments', $this->headers($login->json('access_token')))
            ->assertStatus(403);
    }

    public function test_view_only_group_can_read_but_not_upload_or_sign(): void
    {
        $company = Company::factory()->create();
        $token = $this->tokenForLevel($company, GroupModuleAuthority::VIEW);
        $entityId = (string) Str::uuid();

        $this->getJson("/api/documents/invoice/{$entityId}/attachments", $this->headers($token))->assertOk();
        $this->post(
            "/api/documents/invoice/{$entityId}/attachments",
            ['file' => UploadedFile::fake()->createWithContent('a.txt', 'x')],
            $this->headers($token),
        )->assertStatus(403);
        $this->postJson(
            "/api/documents/invoice/{$entityId}/signatures",
            ['signer_name' => 'X', 'signature_data_uri' => 'data:image/png;base64,AA=='],
            $this->headers($token),
        )->assertStatus(403);
    }

    public function test_disabled_module_is_denied_even_with_full_group_authority(): void
    {
        $company = Company::factory()->create();
        $token = $this->tokenForLevel($company, GroupModuleAuthority::FULL, moduleEnabled: false);

        $this->getJson('/api/documents/invoice/'.Str::uuid().'/attachments', $this->headers($token))
            ->assertStatus(403);
    }
}
