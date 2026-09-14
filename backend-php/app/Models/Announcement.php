<?php

namespace App\Models;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;

/**
 * One "What's New" item shown under the promo video -- a short tag
 * (e.g. "New", "Update") plus a one-line message. Mirrors
 * backend/app/models/announcements.py's Announcement.
 *
 * Global, NOT company-scoped: these are announcements about the
 * software itself (new features, updates), not a per-company message,
 * and the Login page shows them before any company has even been
 * selected -- same reasoning as Company Setup's public-branding
 * endpoint.
 *
 * Soft-delete (`is_active`) follows this system's usual convention,
 * though a genuine delete is also offered from the admin screen since
 * these are marketing blurbs, not audited business/financial records.
 */
class Announcement extends Model
{
    use HasUuidPrimaryKey;

    public $timestamps = false;

    protected $fillable = ['tag', 'text', 'sort_order', 'is_active'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];
}
