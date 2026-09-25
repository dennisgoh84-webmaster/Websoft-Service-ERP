<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\JobOrder;
use App\Models\ModuleCatalog;
use App\Models\ServiceRecord;
use App\Models\ServiceRecordAttachment;
use App\Models\ServiceRecordSignoff;
use App\Models\User;
use App\Services\MobileFileStorage;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Covers App\Http\Controllers\Api\MobileController and
 * App\Services\MobileFileStorage -- converted from
 * backend/app/routers/mobile.py (planned-work.md #1).
 */
class MobileTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'service_records';

    private Company $company;

    private User $engineer;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        ModuleCatalog::firstOrCreate(['key' => self::MODULE], ['name' => 'Service Records', 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $this->company->id, 'module_key' => self::MODULE],
            ['enabled' => true],
        );
        $this->engineer = User::factory()->for($this->company)->create([
            'role' => User::ROLE_OWNER, 'full_name' => 'Alice Tan',
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $this->token = $this->post('/api/auth/login', [
            'username' => $this->engineer->email, 'password' => 'demo1234',
        ])->json('access_token');
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    private function jobOrder(array $attrs = []): JobOrder
    {
        return JobOrder::factory()->create(array_merge([
            'company_id' => $this->company->id,
            'assigned_to_user_id' => $this->engineer->id,
            'status' => JobOrder::STATUS_OPEN,
        ], $attrs));
    }

    private function jpeg(int $w = 400, int $h = 300): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 255, 255, 255));
        ob_start();
        imagejpeg($im);
        $bytes = (string) ob_get_clean();
        imagedestroy($im);

        return $bytes;
    }

    public function test_the_list_shows_only_my_open_job_orders(): void
    {
        $mine = $this->jobOrder(['subject' => 'Mine, open']);
        $this->jobOrder(['subject' => 'Mine, closed', 'status' => JobOrder::STATUS_CLOSED]);
        $other = User::factory()->for($this->company)->create();
        $this->jobOrder(['subject' => 'Someone elses', 'assigned_to_user_id' => $other->id]);

        $body = $this->getJson('/api/mobile/job-orders', $this->headers())->assertOk()->json();

        $this->assertCount(1, $body);
        $this->assertSame($mine->id, $body[0]['id']);
    }

    public function test_a_job_order_assigned_to_someone_else_is_403_not_404(): void
    {
        // "Not yours" is a different fact from "does not exist", and the
        // mobile app says so deliberately.
        $other = User::factory()->for($this->company)->create();
        $theirs = $this->jobOrder(['assigned_to_user_id' => $other->id]);

        $this->getJson("/api/mobile/job-orders/{$theirs->id}", $this->headers())
            ->assertStatus(403)
            ->assertJsonPath('detail', 'This Job Order is not assigned to you');
    }

    public function test_another_companys_job_order_is_404(): void
    {
        $other = Company::factory()->create();
        $theirs = JobOrder::factory()->create([
            'company_id' => $other->id, 'assigned_to_user_id' => $this->engineer->id,
        ]);

        $this->getJson("/api/mobile/job-orders/{$theirs->id}", $this->headers())->assertStatus(404);
    }

    public function test_time_in_creates_a_record_and_claims_an_open_job_order(): void
    {
        $jo = $this->jobOrder();

        $body = $this->postJson("/api/mobile/job-orders/{$jo->id}/time-in", [], $this->headers())
            ->assertOk()->json();

        $this->assertNotEmpty($body['service_record_number']);
        $record = ServiceRecord::findOrFail($body['id']);
        $this->assertNotNull($record->time_in);
        $this->assertNull($record->time_out);
        $this->assertSame(ServiceRecord::STATUS_SUBMITTED, $record->status);
        // Starting work on an OPEN job order moves it to ASSIGNED.
        $this->assertSame(JobOrder::STATUS_ASSIGNED, $jo->fresh()->status);
    }

    public function test_a_second_time_in_is_refused_while_one_is_open(): void
    {
        $jo = $this->jobOrder();
        $first = $this->postJson("/api/mobile/job-orders/{$jo->id}/time-in", [], $this->headers())->assertOk()->json();

        $this->postJson("/api/mobile/job-orders/{$jo->id}/time-in", [], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('detail', "You already have an open time-in ({$first['service_record_number']}). Tap Time Out first.");
    }

    public function test_time_in_is_refused_on_a_closed_job_order(): void
    {
        $jo = $this->jobOrder(['status' => JobOrder::STATUS_CLOSED]);

        $this->postJson("/api/mobile/job-orders/{$jo->id}/time-in", [], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('detail', 'This Job Order is closed or voided');
    }

    public function test_time_out_rounds_minutes_up_to_the_contract_increment(): void
    {
        $jo = $this->jobOrder();
        $started = $this->postJson("/api/mobile/job-orders/{$jo->id}/time-in", [], $this->headers())->assertOk()->json();

        // Backdate the time-in by 20 minutes so the elapsed time is
        // deterministic rather than however fast the test runs.
        $record = ServiceRecord::findOrFail($started['id']);
        $record->forceFill(['time_in' => Carbon::now()->subMinutes(20)])->save();

        $body = $this->postJson("/api/mobile/service-records/{$record->id}/time-out", [
            'completion_status' => 'C', 'is_after_hours' => true, 'work_description' => '  Replaced the PSU  ',
        ], $this->headers())->assertOk()->json();

        $this->assertSame(20, $body['raw_minutes']);
        // 20 minutes rounds up to the next 15-minute increment.
        $this->assertSame(30, $body['rounded_minutes']);

        $record->refresh();
        $this->assertSame(ServiceRecord::COMPLETED, $record->completion_status);
        $this->assertTrue($record->is_after_hours);
        $this->assertSame('Replaced the PSU', $record->work_description, 'the description is trimmed');
    }

    public function test_a_very_short_visit_still_bills_a_minimum_of_one_minute(): void
    {
        $jo = $this->jobOrder();
        $started = $this->postJson("/api/mobile/job-orders/{$jo->id}/time-in", [], $this->headers())->assertOk()->json();

        // Time out immediately: seconds of elapsed time, not zero minutes.
        $body = $this->postJson("/api/mobile/service-records/{$started['id']}/time-out", [], $this->headers())
            ->assertOk()->json();

        $this->assertSame(1, $body['raw_minutes']);
        $this->assertSame(15, $body['rounded_minutes']);
    }

    public function test_time_out_twice_is_refused(): void
    {
        $jo = $this->jobOrder();
        $started = $this->postJson("/api/mobile/job-orders/{$jo->id}/time-in", [], $this->headers())->assertOk()->json();
        $this->postJson("/api/mobile/service-records/{$started['id']}/time-out", [], $this->headers())->assertOk();

        $this->postJson("/api/mobile/service-records/{$started['id']}/time-out", [], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('detail', 'Time Out already recorded');
    }

    public function test_another_engineers_record_cannot_be_timed_out(): void
    {
        $other = User::factory()->for($this->company)->create();
        $jo = $this->jobOrder(['assigned_to_user_id' => $other->id]);
        $record = ServiceRecord::factory()->create([
            'company_id' => $this->company->id, 'job_order_id' => $jo->id,
            'employee_user_id' => $other->id, 'time_in' => Carbon::now(), 'time_out' => null,
        ]);

        $this->postJson("/api/mobile/service-records/{$record->id}/time-out", [], $this->headers())
            ->assertStatus(403)
            ->assertJsonPath('detail', 'This Service Record belongs to another staff member');
    }

    /**
     * The "nothing open" answer must be a LITERAL null, not `{}`.
     * MobileApp.tsx guards on `{openTimeIn && ...}` and then reads
     * fields off it, so `{}` is truthy there and blanks the whole
     * screen with a TypeError. assertExactJson([]) cannot tell the two
     * apart -- json_decode('{}', true) is also [] -- so these assert
     * the raw response body instead.
     */
    public function test_my_open_timein_reports_the_open_record_then_null(): void
    {
        $this->assertSame(
            'null',
            $this->getJson('/api/mobile/my-open-timein', $this->headers())->assertOk()->getContent(),
        );

        $jo = $this->jobOrder(['subject' => 'On site now']);
        $started = $this->postJson("/api/mobile/job-orders/{$jo->id}/time-in", [], $this->headers())->assertOk()->json();

        $this->getJson('/api/mobile/my-open-timein', $this->headers())->assertOk()
            ->assertJsonPath('service_record_id', $started['id'])
            ->assertJsonPath('job_order_subject', 'On site now');

        $this->postJson("/api/mobile/service-records/{$started['id']}/time-out", [], $this->headers())->assertOk();
        $this->assertSame(
            'null',
            $this->getJson('/api/mobile/my-open-timein', $this->headers())->assertOk()->getContent(),
        );
    }

    public function test_photos_and_videos_are_accepted_and_anything_else_refused(): void
    {
        $jo = $this->jobOrder();
        $started = $this->postJson("/api/mobile/job-orders/{$jo->id}/time-in", [], $this->headers())->assertOk()->json();
        $recordId = $started['id'];

        $photo = UploadedFile::fake()->createWithContent('site.jpg', $this->jpeg());
        $this->post("/api/mobile/service-records/{$recordId}/attachments", ['file' => $photo], $this->headers())
            ->assertOk()->assertJsonPath('kind', ServiceRecordAttachment::KIND_WORK_PHOTO);

        $doc = UploadedFile::fake()->createWithContent('notes.pdf', '%PDF-1.4 fake');
        $this->post("/api/mobile/service-records/{$recordId}/attachments", ['file' => $doc], $this->headers())
            ->assertStatus(422)
            ->assertJsonPath('detail', 'Only photos and videos are accepted');
    }

    public function test_an_attachment_can_be_listed_downloaded_and_soft_deleted(): void
    {
        $jo = $this->jobOrder();
        $started = $this->postJson("/api/mobile/job-orders/{$jo->id}/time-in", [], $this->headers())->assertOk()->json();
        $photo = UploadedFile::fake()->createWithContent('site.jpg', $this->jpeg());
        $uploaded = $this->post("/api/mobile/service-records/{$started['id']}/attachments", ['file' => $photo], $this->headers())
            ->assertOk()->json();

        $this->getJson("/api/mobile/service-records/{$started['id']}/attachments", $this->headers())
            ->assertOk()->assertJsonCount(1);

        $this->get("/api/mobile/attachments/{$uploaded['id']}/file", $this->headers())->assertOk();

        $this->deleteJson("/api/mobile/attachments/{$uploaded['id']}", [], $this->headers())
            ->assertOk()->assertJsonPath('deleted', true);

        // Soft delete: hidden from the list, but the row survives, and
        // the file is never removed from disk.
        $this->getJson("/api/mobile/service-records/{$started['id']}/attachments", $this->headers())
            ->assertOk()->assertJsonCount(0);
        $att = ServiceRecordAttachment::findOrFail($uploaded['id']);
        $this->assertTrue($att->is_deleted);
        $this->assertNotNull(
            MobileFileStorage::filePath($att->company_id, $att->service_record_id, $att->stored_filename),
            'the file itself must survive a soft delete',
        );
    }

    public function test_time_in_and_signoff_responses_report_the_real_moment(): void
    {
        // With APP_TIMEZONE=Asia/Singapore, a just-written timestamp re-read
        // from the unrefreshed model came back as the UTC clock labelled
        // +08:00 -- eight hours in the past.
        $jo = $this->jobOrder();
        $now = Carbon::now()->getTimestamp();

        $started = $this->postJson("/api/mobile/job-orders/{$jo->id}/time-in", [], $this->headers())->assertOk()->json();
        $this->assertEqualsWithDelta($now, Carbon::parse($started['time_in'])->getTimestamp(), 60);

        $body = $this->post("/api/mobile/service-records/{$started['id']}/signoff", [
            'signer_name' => 'Mr Lim',
            'signature_data_uri' => 'data:image/png;base64,iVBORw0KGgo=',
            'chop_photo' => UploadedFile::fake()->createWithContent('chop.jpg', $this->jpeg(800, 600)),
        ], $this->headers())->assertOk()->json();
        $this->assertEqualsWithDelta($now, Carbon::parse($body['signed_at'])->getTimestamp(), 60);
    }

    public function test_signoff_watermarks_the_chop_photo_and_is_accepted_once(): void
    {
        $jo = $this->jobOrder();
        $started = $this->postJson("/api/mobile/job-orders/{$jo->id}/time-in", [], $this->headers())->assertOk()->json();
        $record = ServiceRecord::findOrFail($started['id']);

        $chop = UploadedFile::fake()->createWithContent('chop.jpg', $this->jpeg(800, 600));
        $body = $this->post("/api/mobile/service-records/{$record->id}/signoff", [
            'signer_name' => '  Mr Lim  ',
            'signature_data_uri' => 'data:image/png;base64,iVBORw0KGgo=',
            'chop_photo' => $chop,
        ], $this->headers())->assertOk()->json();

        $this->assertSame('Mr Lim', $body['signer_name'], 'the signer name is trimmed');
        $this->assertNotNull($body['chop_attachment_id']);

        // The stored chop is the WATERMARKED image, not the original --
        // which is what ties it to this one Service Record.
        $chopAtt = ServiceRecordAttachment::findOrFail($body['chop_attachment_id']);
        $this->assertSame(ServiceRecordAttachment::KIND_CHOP_PHOTO, $chopAtt->kind);
        $path = MobileFileStorage::filePath(
            $chopAtt->company_id, $chopAtt->service_record_id, $chopAtt->stored_filename
        );
        $stored = imagecreatefromstring((string) file_get_contents($path));
        $bottom = imagecolorat($stored, 400, 580);
        $top = imagecolorat($stored, 400, 30);
        $this->assertNotSame($top, $bottom, 'the watermark strip must actually be drawn');
        $this->assertLessThan(200, ($bottom >> 16) & 255, 'the bottom strip is darkened');

        // One sign-off per Service Record.
        $again = UploadedFile::fake()->createWithContent('chop2.jpg', $this->jpeg());
        $this->post("/api/mobile/service-records/{$record->id}/signoff", [
            'signer_name' => 'Someone Else',
            'signature_data_uri' => 'data:image/png;base64,iVBORw0KGgo=',
            'chop_photo' => $again,
        ], $this->headers())->assertStatus(422)
            ->assertJsonPath('detail', 'This Service Record already has a sign-off');
    }

    public function test_get_signoff_returns_null_until_one_exists(): void
    {
        $jo = $this->jobOrder();
        $started = $this->postJson("/api/mobile/job-orders/{$jo->id}/time-in", [], $this->headers())->assertOk()->json();

        // Literal null, for the same reason as my-open-timein above.
        $this->assertSame(
            'null',
            $this->getJson("/api/mobile/service-records/{$started['id']}/signoff", $this->headers())
                ->assertOk()->getContent(),
        );
    }

    public function test_the_job_order_detail_counts_attachments_and_flags_signoff(): void
    {
        $jo = $this->jobOrder();
        $started = $this->postJson("/api/mobile/job-orders/{$jo->id}/time-in", [], $this->headers())->assertOk()->json();
        $photo = UploadedFile::fake()->createWithContent('site.jpg', $this->jpeg());
        $this->post("/api/mobile/service-records/{$started['id']}/attachments", ['file' => $photo], $this->headers())->assertOk();

        $detail = $this->getJson("/api/mobile/job-orders/{$jo->id}", $this->headers())->assertOk()->json();
        $sr = $detail['service_records'][0];
        $this->assertSame(1, $sr['attachment_count']);
        $this->assertFalse($sr['has_signoff']);
        $this->assertSame('Alice Tan', $sr['employee_name']);

        ServiceRecordSignoff::create([
            'company_id' => $this->company->id, 'service_record_id' => $started['id'],
            'signer_name' => 'Mr Lim', 'signature_data_uri' => 'data:,',
            'signed_by_user_id' => $this->engineer->id, 'signed_at' => Carbon::now(),
        ]);
        $detail = $this->getJson("/api/mobile/job-orders/{$jo->id}", $this->headers())->assertOk()->json();
        $this->assertTrue($detail['service_records'][0]['has_signoff']);
    }
}
