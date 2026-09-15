<?php

namespace Tests\Feature;

use App\Models\AdBannerSettings;
use App\Models\Announcement;
use App\Models\Company;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * deploy/install.sh runs this seeder on every fresh server, so what it
 * produces is what Dennis sees on first login. It is also run twice in
 * practice (a reseed over an existing install), which is the case worth
 * pinning down: it must fill blanks without overwriting anything typed
 * into Company Setup since.
 */
class DatabaseSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_seeds_the_company_letterhead_logo_and_announcements(): void
    {
        $this->seed(DatabaseSeeder::class);

        $company = Company::where('name', 'Webmaster Consultancy Pte Ltd')->firstOrFail();
        $this->assertSame('8 Ubi Road 2 #05-12/13/14 Zervex, Singapore 408538', $company->address);
        $this->assertSame('199802145E', $company->uen);
        $this->assertSame('199802145E', $company->gst_registration_no);
        $this->assertSame('www.websoft.sg', $company->website);
        $this->assertStringStartsWith('data:image/png;base64,', (string) $company->logo);

        $this->assertSame(
            'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
            AdBannerSettings::find(1)?->video_url,
        );
        $this->assertSame(3, Announcement::count());
        $this->assertEqualsCanonicalizing(
            ['New', 'Add-on', 'Update'],
            Announcement::pluck('tag')->all(),
        );
    }

    public function test_reseeding_neither_duplicates_announcements_nor_overwrites_edited_company_details(): void
    {
        $this->seed(DatabaseSeeder::class);

        $company = Company::where('name', 'Webmaster Consultancy Pte Ltd')->firstOrFail();
        $company->address = 'Somewhere Dennis moved the office to';
        $company->logo = 'data:image/png;base64,AAAA';
        $company->save();

        $this->seed(DatabaseSeeder::class);

        $company->refresh();
        $this->assertSame('Somewhere Dennis moved the office to', $company->address);
        $this->assertSame('data:image/png;base64,AAAA', $company->logo);
        $this->assertSame(3, Announcement::count());
        $this->assertSame(1, AdBannerSettings::count());
    }

    public function test_a_blank_field_on_an_existing_company_is_filled_in(): void
    {
        $company = Company::factory()->create([
            'name' => 'Webmaster Consultancy Pte Ltd',
            'address' => null,
            'uen' => null,
        ]);

        $this->seed(DatabaseSeeder::class);

        $company->refresh();
        $this->assertSame('8 Ubi Road 2 #05-12/13/14 Zervex, Singapore 408538', $company->address);
        $this->assertSame('199802145E', $company->uen);
    }
}
