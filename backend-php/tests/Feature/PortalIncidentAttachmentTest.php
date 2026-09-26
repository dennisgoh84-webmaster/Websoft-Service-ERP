<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contact;
use App\Models\DocumentAttachment;
use App\Models\Incident;
use App\Models\PortalUser;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Helpdesk Portal attachments (Dennis, 2026-09-26, decision page):
 * photos, screenshots and PDFs, up to 10 MB each and 5 per incident,
 * added when raising it or later while it is still open. Staff see them
 * as ordinary attachments on the incident.
 */
class PortalIncidentAttachmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private CompanyIndividual $mine;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        config(['websoft.uploads_dir' => sys_get_temp_dir().'/websoft-test-uploads-'.uniqid()]);
        $this->company = Company::factory()->create();
        $this->mine = CompanyIndividual::factory()->for($this->company)->create(['pdpa_consent_given' => true]);
        $contact = Contact::create(['customer_id' => $this->mine->id, 'name' => 'Alice Tan', 'email' => 'alice@mine.example']);
        PortalUser::create([
            'company_id' => $this->company->id, 'contact_id' => $contact->id, 'email' => $contact->email,
            'hashed_password' => PasswordPolicy::hash('portal123'), 'must_change_password' => false,
        ]);
        $this->token = $this->postJson('/api/portal/auth/login', ['email' => $contact->email, 'password' => 'portal123'])->json('portal_token');
    }

    private function h(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    private function raise(): string
    {
        return $this->postJson('/api/portal/incidents', ['subject' => 'Printer jam'], $this->h())->assertOk()->json('id');
    }

    public function test_a_customer_attaches_a_photo_and_a_pdf_and_staff_see_them_on_the_incident(): void
    {
        $id = $this->raise();
        $this->post("/api/portal/incidents/{$id}/attachments", ['file' => UploadedFile::fake()->image('jam.jpg', 400, 300)], $this->h())
            ->assertCreated()->assertJsonPath('original_filename', 'jam.jpg');
        $this->post("/api/portal/incidents/{$id}/attachments", ['file' => UploadedFile::fake()->create('error.pdf', 200, 'application/pdf')], $this->h())
            ->assertCreated();

        $this->getJson("/api/portal/incidents/{$id}/attachments", $this->h())->assertOk()->assertJsonCount(2);
        $a = DocumentAttachment::where('entity_type', 'incident')->where('entity_id', $id)->where('original_filename', 'jam.jpg')->sole();
        $this->assertNull($a->uploaded_by_user_id);
        $this->assertNotNull($a->uploaded_by_portal_user_id);
        $this->assertStringContainsString('Alice Tan', $a->description);
        $this->get("/api/portal/incidents/{$id}/attachments/{$a->id}", $this->h())->assertOk();
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'incident', 'entity_id' => $id, 'action' => 'document_attachment_upload']);
    }

    public function test_only_photos_and_pdfs_up_to_ten_mb_and_five_per_incident(): void
    {
        $id = $this->raise();
        $this->post("/api/portal/incidents/{$id}/attachments", ['file' => UploadedFile::fake()->create('notes.docx', 10, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document')], $this->h())
            ->assertStatus(422);
        $this->post("/api/portal/incidents/{$id}/attachments", ['file' => UploadedFile::fake()->create('big.pdf', 10 * 1024 + 1, 'application/pdf')], $this->h())
            ->assertStatus(422);
        for ($i = 1; $i <= 5; $i++) {
            $this->post("/api/portal/incidents/{$id}/attachments", ['file' => UploadedFile::fake()->image("p{$i}.png")], $this->h())->assertCreated();
        }
        $this->post("/api/portal/incidents/{$id}/attachments", ['file' => UploadedFile::fake()->image('p6.png')], $this->h())
            ->assertStatus(422)->assertJsonFragment(['detail' => 'An incident can have up to 5 files.']);
    }

    public function test_files_are_added_only_while_the_incident_is_open_and_only_to_ones_own(): void
    {
        $id = $this->raise();
        Incident::whereKey($id)->update(['status' => Incident::STATUS_CLOSED]);
        $this->post("/api/portal/incidents/{$id}/attachments", ['file' => UploadedFile::fake()->image('late.png')], $this->h())
            ->assertStatus(422)->assertJsonFragment(['detail' => 'Files can be added only while the incident is open.']);

        $theirs = CompanyIndividual::factory()->for($this->company)->create();
        $other = Incident::factory()->create(['company_id' => $this->company->id, 'customer_id' => $theirs->id]);
        $this->post("/api/portal/incidents/{$other->id}/attachments", ['file' => UploadedFile::fake()->image('x.png')], $this->h())->assertStatus(404);
        $this->getJson("/api/portal/incidents/{$other->id}/attachments", $this->h())->assertStatus(404);
    }
}
