<?php

namespace Tests\Feature;

use App\Models\AdBannerSettings;
use App\Models\Announcement;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\AdBannerVideo;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Covers App\Http\Controllers\Api\AnnouncementController -- converted
 * from backend/app/routers/announcements.py.
 *
 * Two things differ from every other module's test template, both
 * deliberate and both pinned below: GET /announcements/public is
 * unauthenticated (the Login page reads it), and announcements are
 * global rather than company-scoped, so the usual "another company's
 * record is a 404" case is replaced by its opposite -- a second
 * company sees the SAME announcements, which is the point of the
 * module.
 */
class AnnouncementTest extends TestCase
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

    public function test_public_banner_is_unauthenticated_and_shows_only_active_items_in_order(): void
    {
        Announcement::create(['tag' => 'New', 'text' => 'Second', 'sort_order' => 2]);
        Announcement::create(['tag' => 'Update', 'text' => 'First', 'sort_order' => 1]);
        Announcement::create(['text' => 'Hidden', 'sort_order' => 0, 'is_active' => false]);

        // No Authorization header at all -- the Login page calls this
        // before anyone has signed in.
        $response = $this->getJson('/api/announcements/public/login');

        $response->assertOk()
            ->assertJsonPath('video_url', null)
            ->assertJsonCount(2, 'items')
            ->assertJsonPath('items.0.text', 'First')
            ->assertJsonPath('items.1.text', 'Second');
        // Each slot's row is created on first read, so the endpoint
        // never 404s on a fresh install.
        $this->assertNotNull(AdBannerSettings::find('login'));
    }

    public function test_unknown_slot_is_404_on_every_video_endpoint(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->getJson('/api/announcements/public/sidebar')->assertStatus(404);
        $this->getJson('/api/announcements/settings/sidebar', $this->headers($token))->assertStatus(404);
        $this->patchJson('/api/announcements/settings/sidebar', [], $this->headers($token))->assertStatus(404);

        // Validation of the uploaded file itself runs before the slot
        // check, so a genuinely valid file is needed here to prove the
        // slot check (not the file requirement) is what 404s.
        $file = UploadedFile::fake()->createWithContent('promo-clip.mp4', 'video bytes');
        $this->post('/api/announcements/settings/sidebar/video', ['video' => $file], $this->headers($token))->assertStatus(404);
    }

    public function test_owner_can_set_and_read_back_the_promo_video_url(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->patchJson('/api/announcements/settings/login', ['video_url' => 'https://example.com/promo.mp4'], $this->headers($token))
            ->assertOk()
            ->assertJson(['video_url' => 'https://example.com/promo.mp4']);

        $this->getJson('/api/announcements/settings/login', $this->headers($token))
            ->assertOk()
            ->assertJson(['video_url' => 'https://example.com/promo.mp4']);
        $this->getJson('/api/announcements/public/login')
            ->assertOk()
            ->assertJsonPath('video_url', 'https://example.com/promo.mp4');

        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'ad_banner_settings',
            'entity_id' => '00000000-0000-0000-0000-000000000001',
            'action' => 'updated',
        ]);

        // Sending no video_url clears it -- AdBannerSettingsUpdate
        // defaults the field to None, so this is a full replace, not a
        // partial update. Preserved rather than "improved".
        $this->patchJson('/api/announcements/settings/login', [], $this->headers($token))
            ->assertOk()
            ->assertJson(['video_url' => null]);
    }

    public function test_the_two_slots_are_independent(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->patchJson('/api/announcements/settings/login', ['video_url' => 'https://example.com/login.mp4'], $this->headers($token))
            ->assertOk();
        $this->patchJson('/api/announcements/settings/app', ['video_url' => 'https://example.com/app.mp4'], $this->headers($token))
            ->assertOk();

        $this->getJson('/api/announcements/public/login')->assertOk()->assertJsonPath('video_url', 'https://example.com/login.mp4');
        $this->getJson('/api/announcements/public/app')->assertOk()->assertJsonPath('video_url', 'https://example.com/app.mp4');

        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'ad_banner_settings',
            'entity_id' => '00000000-0000-0000-0000-000000000005',
            'action' => 'updated',
        ]);

        // Clearing one slot leaves the other alone.
        $this->patchJson('/api/announcements/settings/login', [], $this->headers($token))->assertOk();
        $this->getJson('/api/announcements/public/login')->assertOk()->assertJsonPath('video_url', null);
        $this->getJson('/api/announcements/public/app')->assertOk()->assertJsonPath('video_url', 'https://example.com/app.mp4');
    }

    public function test_owner_can_upload_a_video_file_and_it_is_served_back(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $file = UploadedFile::fake()->createWithContent('promo-clip.mp4', 'pretend this is video bytes');

        $upload = $this->post('/api/announcements/settings/login/video', ['video' => $file], $this->headers($token));
        $upload->assertOk()
            ->assertJsonPath('video_source', 'upload')
            ->assertJsonPath('video_original_filename', 'promo-clip.mp4')
            ->assertJsonPath('video_file_size_bytes', strlen('pretend this is video bytes'));
        $videoUrl = $upload->json('video_url');
        $this->assertStringStartsWith('/api/announcements/video/', $videoUrl);

        // The public banner (unauthenticated) resolves to the same served URL.
        $this->getJson('/api/announcements/public/login')->assertOk()->assertJsonPath('video_url', $videoUrl);
        // The other slot is untouched -- it doesn't resolve this filename.
        $this->getJson('/api/announcements/public/app')->assertOk()->assertJsonPath('video_url', null);

        // And that URL actually serves the uploaded bytes back, unauthenticated,
        // with a real video Content-Type -- pinning a real bug found by hand:
        // this fake file's CONTENT doesn't read as video (it's plain text), so
        // content-sniffing it (getMimeType()) served `text/plain`, and the
        // browser-reported getClientMimeType() proved unreliable too (Laravel's
        // own upload-faking helper calls a .mp4 `application/mp4`). Either
        // silently breaks playback since <video> trusts the response header.
        // The fix derives the type strictly from the file extension instead.
        $served = $this->get($videoUrl)->assertOk();
        $served->assertHeader('Content-Type', 'video/mp4');
        $this->assertSame('pretend this is video bytes', file_get_contents($served->baseResponse->getFile()->getPathname()));

        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'ad_banner_settings', 'action' => 'video_uploaded',
        ]);
    }

    public function test_uploading_a_video_clears_a_previously_set_url_and_vice_versa(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->patchJson('/api/announcements/settings/login', ['video_url' => 'https://example.com/promo.mp4'], $this->headers($token))
            ->assertOk();

        $file = UploadedFile::fake()->createWithContent('promo-clip.mp4', 'video bytes');
        $upload = $this->post('/api/announcements/settings/login/video', ['video' => $file], $this->headers($token))
            ->assertOk()->assertJsonPath('video_source', 'upload');
        $storedFilename = basename((string) $upload->json('video_url'));
        $this->assertNotNull(AdBannerVideo::path($storedFilename), 'file should exist on disk after upload');

        // Setting a URL now replaces the upload -- and deletes it from disk.
        $this->patchJson('/api/announcements/settings/login', ['video_url' => 'https://example.com/other.mp4'], $this->headers($token))
            ->assertOk()
            ->assertJsonPath('video_source', 'url')
            ->assertJsonPath('video_url', 'https://example.com/other.mp4');
        $this->assertNull(AdBannerVideo::path($storedFilename), 'old file should be deleted once the URL replaces it');
    }

    public function test_clearing_settings_removes_an_uploaded_file_from_disk_too(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $file = UploadedFile::fake()->createWithContent('promo-clip.mp4', 'video bytes');
        $upload = $this->post('/api/announcements/settings/app/video', ['video' => $file], $this->headers($token))->assertOk();
        $storedFilename = basename((string) $upload->json('video_url'));

        $this->patchJson('/api/announcements/settings/app', [], $this->headers($token))
            ->assertOk()->assertJsonPath('video_source', 'none')->assertJsonPath('video_url', null);
        $this->assertNull(AdBannerVideo::path($storedFilename));
    }

    public function test_video_upload_is_capped_in_size(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $oversize = UploadedFile::fake()->create('too-big.mp4', 102401); // KB, 1 over the 100MB cap

        $this->post('/api/announcements/settings/login/video', ['video' => $oversize], $this->headers($token))
            ->assertStatus(422);
    }

    public function test_serving_the_video_refuses_a_stale_or_unknown_filename(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $file = UploadedFile::fake()->createWithContent('promo-clip.mp4', 'video bytes');
        $this->post('/api/announcements/settings/login/video', ['video' => $file], $this->headers($token))->assertOk();

        $this->get('/api/announcements/video/not-the-real-one.mp4')->assertStatus(404);

        // No video configured at all -> every filename 404s.
        AdBannerSettings::find('login')->update(['video_stored_filename' => null]);
        $this->get('/api/announcements/video/anything.mp4')->assertStatus(404);
    }

    public function test_create_list_update_and_delete_an_announcement(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $created = $this->postJson(
            '/api/announcements',
            ['tag' => 'New', 'text' => 'Quotations module is live', 'sort_order' => 3],
            $this->headers($token),
        );
        $created->assertOk()->assertJson([
            'tag' => 'New', 'text' => 'Quotations module is live', 'sort_order' => 3, 'is_active' => true,
        ]);
        $id = $created->json('id');
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'announcement', 'entity_id' => $id, 'action' => 'created',
        ]);

        // The admin list includes inactive items; /public does not.
        $this->patchJson("/api/announcements/{$id}", ['is_active' => false], $this->headers($token))
            ->assertOk()
            ->assertJson(['is_active' => false, 'text' => 'Quotations module is live']);
        $this->getJson('/api/announcements', $this->headers($token))->assertOk()->assertJsonCount(1);
        $this->getJson('/api/announcements/public/login')->assertOk()->assertJsonCount(0, 'items');

        $this->delete("/api/announcements/{$id}", [], $this->headers($token))->assertStatus(204);
        // A genuine delete, not a soft-delete -- see the Python
        // router's own comment, carried across to the controller.
        $this->assertSame(0, Announcement::count());
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'announcement', 'entity_id' => $id, 'action' => 'deleted',
        ]);
    }

    public function test_update_only_touches_the_fields_actually_sent(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);
        $announcement = Announcement::create(['tag' => 'New', 'text' => 'Original', 'sort_order' => 5]);

        $this->patchJson("/api/announcements/{$announcement->id}", ['text' => 'Edited'], $this->headers($token))
            ->assertOk()
            ->assertJson(['tag' => 'New', 'text' => 'Edited', 'sort_order' => 5]);
    }

    public function test_announcement_text_is_required_and_capped(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->postJson('/api/announcements', ['text' => ''], $this->headers($token))->assertStatus(422);
        $this->postJson('/api/announcements', ['text' => str_repeat('a', 501)], $this->headers($token))->assertStatus(422);
        $this->postJson('/api/announcements', ['text' => 'ok', 'tag' => str_repeat('t', 31)], $this->headers($token))
            ->assertStatus(422);
    }

    public function test_unknown_announcement_is_404(): void
    {
        $company = Company::factory()->create();
        $token = $this->ownerToken($company);

        $this->patchJson('/api/announcements/'.Str::uuid(), ['text' => 'x'], $this->headers($token))->assertStatus(404);
        $this->delete('/api/announcements/'.Str::uuid(), [], $this->headers($token))->assertStatus(404);
    }

    /**
     * The opposite of every other module's multi-company test, and
     * deliberately so: announcements are about the software itself, so
     * a second company sees the same ones rather than a 404.
     */
    public function test_announcements_are_global_not_company_scoped(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $tokenA = $this->ownerToken($companyA);
        $tokenB = $this->ownerToken($companyB);

        $this->postJson('/api/announcements', ['text' => 'Platform-wide notice'], $this->headers($tokenA))->assertOk();

        $this->getJson('/api/announcements', $this->headers($tokenB))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.text', 'Platform-wide notice');
    }

    public function test_user_with_no_group_is_denied(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER,
            'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $login = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234']);

        $this->getJson('/api/announcements', $this->headers($login->json('access_token')))->assertStatus(403);
    }

    public function test_view_only_can_list_but_even_edit_cannot_write(): void
    {
        $company = Company::factory()->create();

        $viewToken = $this->tokenForLevel($company, GroupModuleAuthority::VIEW);
        $this->getJson('/api/announcements', $this->headers($viewToken))->assertOk();
        // Reading the settings is FULL-only in the Python router, not
        // VIEW -- unusual, and preserved rather than relaxed.
        $this->getJson('/api/announcements/settings/login', $this->headers($viewToken))->assertStatus(403);

        $editToken = $this->tokenForLevel($company, GroupModuleAuthority::EDIT);
        $this->postJson('/api/announcements', ['text' => 'x'], $this->headers($editToken))->assertStatus(403);
    }

    public function test_disabled_module_is_denied_even_with_full_group_authority(): void
    {
        $company = Company::factory()->create();
        $token = $this->tokenForLevel($company, GroupModuleAuthority::FULL, moduleEnabled: false);

        $this->getJson('/api/announcements', $this->headers($token))->assertStatus(403);
        // ...but the public banner still works, since it has no gate at
        // all -- the Login page must never depend on Module Control.
        $this->getJson('/api/announcements/public/app')->assertOk();
    }
}
