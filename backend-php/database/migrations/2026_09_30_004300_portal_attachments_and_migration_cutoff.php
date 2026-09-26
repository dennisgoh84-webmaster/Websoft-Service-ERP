<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Backlog 2 (Dennis, 2026-09-26, decision page):
 *
 * - Customers attach photos, screenshots and PDFs to their incidents on
 *   the Helpdesk Portal. A portal login is not a staff user, so a
 *   document attachment now records either the staff uploader or the
 *   portal uploader (document_attachments.uploaded_by_portal_user_id),
 *   never neither.
 * - Data Migration's cut-off date, picked when a batch is dry run
 *   (migration_batches.cutoff_date): transactions dated before it are
 *   left out unless still open.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->uuid('uploaded_by_portal_user_id')->nullable();
            $table->foreign('uploaded_by_portal_user_id')->references('id')->on('portal_users');
        });
        DB::statement('ALTER TABLE document_attachments ALTER COLUMN uploaded_by_user_id DROP NOT NULL');
        DB::statement('ALTER TABLE document_attachments ADD CONSTRAINT ck_document_attachment_uploader
            CHECK (uploaded_by_user_id IS NOT NULL OR uploaded_by_portal_user_id IS NOT NULL)');

        Schema::table('migration_batches', function (Blueprint $table) {
            $table->date('cutoff_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('migration_batches', function (Blueprint $table) {
            $table->dropColumn('cutoff_date');
        });
        DB::statement('ALTER TABLE document_attachments DROP CONSTRAINT ck_document_attachment_uploader');
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->dropForeign(['uploaded_by_portal_user_id']);
            $table->dropColumn('uploaded_by_portal_user_id');
        });
        DB::statement('ALTER TABLE document_attachments ALTER COLUMN uploaded_by_user_id SET NOT NULL');
    }
};
