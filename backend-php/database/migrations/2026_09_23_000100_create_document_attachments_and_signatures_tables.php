<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/documents.py -- generic eDocument
// attachments and eSignature for every document type (built
// 2026-09-12, planned-work.md #3). Additive migration; nothing
// existing is altered. See docs/php-conversion-plan.md.
//
// entity_type is a plain string column rather than a PostgreSQL enum:
// the Python model declares Enum(DocumentEntityType,
// name="document_entity_type"), but the values are validated in the
// request layer here (App\Models\DocumentAttachment::ENTITY_TYPES),
// and every other converted module in backend-php stores its Python
// enums the same way -- adding a document type stays a code change,
// not a schema migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('entity_type', 40);
            $table->uuid('entity_id')->index();
            $table->uuid('uploaded_by_user_id');

            $table->string('original_filename', 500);
            $table->string('stored_filename', 500);
            $table->string('content_type', 200);
            $table->integer('file_size_bytes');
            $table->string('description', 500)->nullable();

            $table->timestampTz('uploaded_at')->useCurrent();
            // Soft-delete: never permanently removed (CLAUDE.md).
            $table->boolean('is_deleted')->default(false);

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('uploaded_by_user_id')->references('id')->on('users');
        });

        Schema::create('document_signatures', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('entity_type', 40);
            $table->uuid('entity_id')->index();

            $table->uuid('signer_user_id');
            $table->string('signer_name', 255);
            $table->text('signature_data_uri');
            // e.g. "Prepared by", "Approved by", "Authorized signatory"
            $table->string('role_label', 100)->nullable();

            $table->timestampTz('signed_at')->useCurrent();
            $table->boolean('is_deleted')->default(false);

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('signer_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_signatures');
        Schema::dropIfExists('document_attachments');
    }
};
