<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A GST return already submitted to IRAS can be revised and submitted
 * again, keeping the old record (Dennis, 2026-09-26: "Have to allow
 * resubmission like revision but have to keep the old record").
 *
 * - revision_opened_by_user_id / revision_opened_at / revision_reason sit
 *   on the submitted return being revised: who opened the revision, when
 *   and why. Its own figures, documents and submission stay as they were.
 * - revises_gst_return_id sits on the new version: the submitted return
 *   it revises.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gst_returns', function (Blueprint $table) {
            $table->uuid('revision_opened_by_user_id')->nullable();
            $table->timestampTz('revision_opened_at')->nullable();
            $table->text('revision_reason')->nullable();
            $table->uuid('revises_gst_return_id')->nullable();
            $table->foreign('revision_opened_by_user_id')->references('id')->on('users');
            $table->foreign('revises_gst_return_id')->references('id')->on('gst_returns');
        });
    }

    public function down(): void
    {
        Schema::table('gst_returns', function (Blueprint $table) {
            $table->dropForeign(['revision_opened_by_user_id']);
            $table->dropForeign(['revises_gst_return_id']);
            $table->dropColumn(['revision_opened_by_user_id', 'revision_opened_at', 'revision_reason', 'revises_gst_return_id']);
        });
    }
};
