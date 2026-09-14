<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A single-row settings table holding the promo video URL (see
 * frontend/src/components/PromoVideoPanel.tsx). Mirrors
 * backend/app/models/announcements.py's AdBannerSettings.
 *
 * Singleton pattern: AnnouncementController always reads/creates the
 * one row with a fixed id (1) rather than filtering a list, since
 * there is exactly one video slot to manage. An integer primary key,
 * not a UUID -- so this model deliberately does NOT use
 * HasUuidPrimaryKey, unlike every other model here.
 *
 * A URL, not an uploaded file: a video is far too large to hold
 * inline the way Company::$logo / User::$photo do, and this system
 * has no video-hosting infrastructure -- see
 * docs/open-business-decisions.md #28.3.
 */
class AdBannerSettings extends Model
{
    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'video_url'];

    protected $casts = ['updated_at' => 'datetime'];
}
