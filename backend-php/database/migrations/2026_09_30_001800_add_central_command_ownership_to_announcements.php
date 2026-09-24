<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Records which promo content Central Command owns, so it becomes
 * one-way -- Dennis, 2026-09-24: "when it's pushed to the client,
 * they cannot amend it, should be one way only for advertisement and
 * video". A client keeps its own editable "company announcements"
 * in the same table, distinguished by `source`; those never flow
 * back to Central Command.
 *
 * `announcements.source`: 'central' (pushed by Central Command --
 * read-only here) or 'local' (added on this install -- fully
 * editable). Rows Central Command pushed BEFORE this migration cannot
 * be told apart from local ones, so they start as 'local' and become
 * 'central' the next time Central Command pushes them (its upsert
 * writes the column).
 *
 * `ad_banner_settings.managed_by_central_command`: true while Central
 * Command owns that slot's video -- set on every push of a URL,
 * cleared when Central Command pushes an empty URL, which hands the
 * slot back to the client.
 *
 * Both columns are part of the schema contract with Central Command
 * (docs/central-command-schema-contract.md in either repo).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE announcements ADD COLUMN source VARCHAR(10) NOT NULL DEFAULT 'local'");
        DB::statement("ALTER TABLE announcements ADD CONSTRAINT announcements_source_check CHECK (source IN ('central', 'local'))");
        DB::statement('ALTER TABLE ad_banner_settings ADD COLUMN managed_by_central_command BOOLEAN NOT NULL DEFAULT FALSE');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE ad_banner_settings DROP COLUMN managed_by_central_command');
        DB::statement('ALTER TABLE announcements DROP CONSTRAINT announcements_source_check');
        DB::statement('ALTER TABLE announcements DROP COLUMN source');
    }
};
