<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospect_activities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            // The prospect/lead is a Company/Individual record (customer).
            $table->uuid('customer_id');
            // Type: call, email, meeting, note, follow_up, etc.
            $table->string('activity_type', 50);
            // Subject/title of the activity.
            $table->string('subject');
            // Full description/notes.
            $table->text('description')->nullable();
            // When the activity occurred.
            $table->dateTime('activity_date')->nullable();
            // Outcome or status: planned, completed, pending, etc.
            $table->string('status', 50)->default('completed');

            // Staff who created this record.
            $table->uuid('created_by_user_id')->nullable();
            // Staff who last edited this record.
            $table->uuid('last_edited_by_user_id')->nullable();

            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrentOnUpdate();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('customer_id')->references('id')->on('company_individuals');
            $table->foreign('created_by_user_id')->references('id')->on('users');
            $table->foreign('last_edited_by_user_id')->references('id')->on('users');
            $table->index(['company_id', 'customer_id']);
            $table->index(['company_id', 'created_by_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prospect_activities');
    }
};
