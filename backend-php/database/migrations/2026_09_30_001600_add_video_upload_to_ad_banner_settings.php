<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets the promo ad banner's video be an uploaded file, not only an
 * external URL -- reverses decision #28.3/#30.2
 * (docs/open-business-decisions.md), made when this was thought too
 * big to build; Dennis asked for it directly 2026-09-16. `video_url`
 * is unchanged and still works (an admin can still point at an
 * externally-hosted clip); these columns describe an uploaded file
 * stored under `uploads_dir`/ad_banner (App\Services\AdBannerVideo),
 * which takes priority over `video_url` when both are present --
 * AnnouncementController clears whichever one it doesn't own on
 * every write, so only one is ever live at a time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ad_banner_settings', function (Blueprint $table) {
            $table->string('video_stored_filename', 255)->nullable();
            $table->string('video_original_filename', 255)->nullable();
            $table->string('video_content_type', 100)->nullable();
            $table->bigInteger('video_file_size_bytes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ad_banner_settings', function (Blueprint $table) {
            $table->dropColumn([
                'video_stored_filename', 'video_original_filename', 'video_content_type', 'video_file_size_bytes',
            ]);
        });
    }
};
