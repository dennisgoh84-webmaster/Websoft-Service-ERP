<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mobile Web App attachments and customer sign-off. Mirrors
 * backend/app/models/attachments.py (planned-work.md #1).
 *
 * DISTINCT from `document_attachments`, deliberately: that is the
 * generic panel mounted on ~12 document detail pages, any file type,
 * with its own soft-delete. These are the field engineer's work photos
 * and videos plus the watermarked chop photo, which carry rules the
 * generic panel does not (images and videos only; a chop photo is
 * watermarked with the Service Record number and timestamp so it
 * cannot be reused on another record). Python keeps them apart for the
 * same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_record_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('service_record_id');
            $table->uuid('uploaded_by_user_id');
            // work_photo | work_video | chop_photo
            $table->string('kind', 20);
            $table->string('original_filename', 500);
            $table->string('stored_filename', 500);
            $table->string('content_type', 200);
            $table->integer('file_size_bytes');
            $table->timestampTz('uploaded_at')->useCurrent();
            // Soft delete only -- the file stays on disk, matching how
            // the generic document panel behaves.
            $table->boolean('is_deleted')->default(false);

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('service_record_id')->references('id')->on('service_records');
            $table->foreign('uploaded_by_user_id')->references('id')->on('users');
            $table->index(['service_record_id', 'is_deleted']);
        });

        Schema::create('service_record_signoffs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('service_record_id');
            $table->string('signer_name', 255);
            // The finger-drawn signature, stored as the data URI the
            // canvas produces (confirmed 2026-09-12).
            $table->text('signature_data_uri');
            $table->uuid('chop_attachment_id')->nullable();
            $table->timestampTz('signed_at')->useCurrent();
            $table->uuid('signed_by_user_id');

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('service_record_id')->references('id')->on('service_records');
            $table->foreign('chop_attachment_id')->references('id')->on('service_record_attachments');
            $table->foreign('signed_by_user_id')->references('id')->on('users');
            // One sign-off per Service Record.
            $table->unique('service_record_id', 'uq_service_record_signoff');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_record_signoffs');
        Schema::dropIfExists('service_record_attachments');
    }
};
