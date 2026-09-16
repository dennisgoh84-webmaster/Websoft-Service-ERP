<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Two independent rows, one per slot, each holding a promo video --
 * either an external URL or an uploaded file (see
 * frontend/src/components/PromoVideoPanel.tsx). Split from a single
 * shared setting 2026-09-16 at Dennis's direct request ("the setting
 * should be separate for login page and inside side menu advert
 * video"); before that this mirrored backend/app/models/
 * announcements.py's AdBannerSettings 1:1 as a fixed-id=1 singleton.
 *
 * Keyed by `slot` (`SLOT_LOGIN` = the Login page before signing in,
 * `SLOT_APP` = the banner shown on every page after signing in) --
 * the same purpose-keyed pattern SystemMailSetting already uses for
 * its two mailboxes, not the AdvertisementController-managed pattern
 * this used to be built on. A varchar primary key, not a UUID -- so
 * this model deliberately does NOT use HasUuidPrimaryKey, unlike every
 * other model here.
 *
 * Exactly one of the two video sources lives at a time PER ROW:
 * `video_url` (an external link) or the four `video_*` upload columns
 * (a file stored on disk under `uploads_dir`/ad_banner -- see
 * App\Services\AdBannerVideo). AnnouncementController clears whichever
 * one a row doesn't own on every write. Each slot's uploaded file is
 * its own independent file on disk, even if two slots happen to carry
 * the same content (see the migration that introduced `slot`) --
 * removing one slot's video must never delete a file the other slot
 * is still serving.
 *
 * Originally URL-only (docs/open-business-decisions.md #28.3/#30.2 --
 * "a video is too large to hold inline... no video-hosting
 * infrastructure"); upload support was added 2026-09-16, storing the
 * file on disk rather than inline in the row the way Company::$logo /
 * User::$photo are, exactly the scale problem that reasoning flagged.
 */
class AdBannerSettings extends Model
{
    public const SLOT_LOGIN = 'login';

    public const SLOT_APP = 'app';

    public const SLOTS = [self::SLOT_LOGIN, self::SLOT_APP];

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'slot';

    protected $keyType = 'string';

    protected $fillable = [
        'slot', 'video_url',
        'video_stored_filename', 'video_original_filename', 'video_content_type', 'video_file_size_bytes',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
        'video_file_size_bytes' => 'integer',
    ];

    public function hasUploadedVideo(): bool
    {
        return (bool) $this->video_stored_filename;
    }
}
