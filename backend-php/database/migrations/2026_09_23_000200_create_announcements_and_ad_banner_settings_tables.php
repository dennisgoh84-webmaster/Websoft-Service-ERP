<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/announcements.py -- platform
// announcements + the promo video URL shown on the ad banner (Login
// page and every page after signing in). Additive migration. See
// docs/php-conversion-plan.md.
//
// Deliberately NOT company-scoped (no company_id column): these are
// announcements about the software itself, and the Login page shows
// them before any company has been selected -- same reasoning as
// Company Setup's public-branding endpoint. Column names, types and
// nullability are kept identical to the SQLAlchemy model on purpose:
// docs/planned-work.md #8a records that the future, separate "Server
// Company Central Command" application writes advertisements straight
// into this table, which makes its shape a schema contract, not just
// an internal detail.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('announcements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('tag', 30)->nullable();
            $table->text('text');
            $table->integer('sort_order')->default(0);
            // Soft-delete/hide flag. A genuine delete is also offered
            // from the admin screen -- see AnnouncementController.
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
        });

        // Singleton settings row (id = 1) -- an integer primary key,
        // not a UUID, exactly as the Python model declares it.
        Schema::create('ad_banner_settings', function (Blueprint $table) {
            $table->integer('id')->primary();
            $table->string('video_url', 1000)->nullable();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ad_banner_settings');
        Schema::dropIfExists('announcements');
    }
};
