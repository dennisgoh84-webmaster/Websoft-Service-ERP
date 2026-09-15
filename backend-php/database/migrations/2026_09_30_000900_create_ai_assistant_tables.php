<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Assistant, first slice (docs/planned-work.md #12, built
 * 2026-09-15): incident triage + resolution suggestions.
 *
 * `ai_settings` is ONE global row (key 'default'), like
 * system_mail_settings: the model-provider API key is an install-level
 * secret Central Command may one day push (planned-work #8c), not a
 * per-company value; per-company licensing is Module Control's
 * `ai_assistant` key. `ai_interactions` is the audit of every call
 * made to the model (decision 12.3: log them like everything else) --
 * who asked, about what, which model, how many tokens, what came back.
 * The prompt itself is NOT stored: it can carry customer text, and the
 * interaction row plus the Event Log entry are the record.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_settings', function (Blueprint $table) {
            $table->string('key', 20)->primary();
            $table->text('api_key')->nullable(); // encrypted at rest, write-only through the API
            $table->string('model', 60)->default('claude-opus-5');
            $table->boolean('redact_personal_data')->default(true); // decision 12.1
            $table->timestampTz('updated_at')->nullable();
        });

        Schema::create('ai_interactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('user_id')->nullable();
            $table->string('feature', 40); // incident_triage
            $table->string('entity_type', 40)->nullable();
            $table->uuid('entity_id')->nullable();
            $table->string('model', 60);
            $table->string('status', 20); // ok|refused|error
            $table->text('error')->nullable();
            $table->integer('input_tokens')->default(0);
            $table->integer('output_tokens')->default(0);
            $table->jsonb('response')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['company_id', 'feature', 'entity_id']);
            $table->index(['company_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_interactions');
        Schema::dropIfExists('ai_settings');
    }
};
