<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Splits the promo video into two independent settings -- Dennis,
 * 2026-09-16: "the setting should be separate for login page and
 * inside side menu advert video" -- one for the Login page
 * (`slot = 'login'`), one for every page after signing in
 * (`slot = 'app'`), each with its own URL-or-upload the same way the
 * single setting worked before. Converts `ad_banner_settings` from a
 * fixed-id=1 singleton to a table keyed by `slot`, the same pattern
 * `system_mail_settings` already uses for its two purposes.
 *
 * The existing row becomes `login`, and an `app` row is created
 * alongside it carrying the SAME settings, so an install upgrading
 * from the single-setting version keeps showing exactly what it
 * showed before in both places until Dennis deliberately splits them
 * apart. A row with an uploaded file gets its file physically copied
 * (not just referenced) so each slot owns an independent file on disk
 * -- otherwise removing one slot's video later would delete the file
 * the other slot is still serving.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE ad_banner_settings ADD COLUMN slot VARCHAR(20)');
        DB::statement("UPDATE ad_banner_settings SET slot = 'login' WHERE id = 1");

        $existing = DB::table('ad_banner_settings')->where('slot', 'login')->first();

        DB::statement('ALTER TABLE ad_banner_settings DROP CONSTRAINT ad_banner_settings_pkey');
        DB::statement('ALTER TABLE ad_banner_settings DROP COLUMN id');
        DB::statement('ALTER TABLE ad_banner_settings ALTER COLUMN slot SET NOT NULL');
        DB::statement('ALTER TABLE ad_banner_settings ADD PRIMARY KEY (slot)');

        if ($existing) {
            $appStoredFilename = null;
            if ($existing->video_stored_filename) {
                $dir = rtrim((string) config('websoft.uploads_dir'), '/').'/ad_banner';
                $srcPath = $dir.'/'.$existing->video_stored_filename;
                if (is_file($srcPath)) {
                    $ext = pathinfo($existing->video_stored_filename, PATHINFO_EXTENSION);
                    $appStoredFilename = ((string) Str::uuid()).($ext !== '' ? '.'.$ext : '');
                    copy($srcPath, $dir.'/'.$appStoredFilename);
                }
            }

            DB::table('ad_banner_settings')->insert([
                'slot' => 'app',
                'video_url' => $existing->video_url,
                'video_stored_filename' => $appStoredFilename,
                'video_original_filename' => $existing->video_original_filename,
                'video_content_type' => $existing->video_content_type,
                'video_file_size_bytes' => $existing->video_file_size_bytes,
                'updated_at' => $existing->updated_at,
            ]);
        }
    }

    public function down(): void
    {
        DB::statement("DELETE FROM ad_banner_settings WHERE slot = 'app'");
        DB::statement('ALTER TABLE ad_banner_settings ADD COLUMN id INTEGER');
        DB::statement("UPDATE ad_banner_settings SET id = 1 WHERE slot = 'login'");
        DB::statement('ALTER TABLE ad_banner_settings DROP CONSTRAINT ad_banner_settings_pkey');
        DB::statement('ALTER TABLE ad_banner_settings DROP COLUMN slot');
        DB::statement('ALTER TABLE ad_banner_settings ALTER COLUMN id SET NOT NULL');
        DB::statement('ALTER TABLE ad_banner_settings ADD PRIMARY KEY (id)');
    }
};
