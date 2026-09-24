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
 *
 * `source` says who owns the row: SOURCE_CENTRAL rows were pushed by
 * Central Command and are read-only on this install (one-way, per
 * Dennis 2026-09-24); SOURCE_LOCAL rows are this company's own
 * announcements, fully editable here and never sent back.
 */
class Announcement extends Model
{
    use HasUuidPrimaryKey;

    public const SOURCE_CENTRAL = 'central';

    public const SOURCE_LOCAL = 'local';

    public $timestamps = false;

    protected $fillable = ['tag', 'text', 'sort_order', 'is_active', 'source'];

    public function isManagedByCentralCommand(): bool
    {
        return $this->source === self::SOURCE_CENTRAL;
    }

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'created_at' => 'datetime',
    ];
}
