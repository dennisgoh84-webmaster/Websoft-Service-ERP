<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/core.py's Company / backend's Alembic
// migrations for the companies table -- see that model for full field
// rationale (multi-company, Singapore tax-invoice fields, approval
// thresholds deliberately left nullable/unset).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('country', 100)->default('Singapore');
            $table->string('currency', 3)->default('SGD');
            $table->string('timezone', 50)->default('Asia/Singapore');
            $table->text('logo')->nullable();
            $table->text('address')->nullable();
            $table->string('gst_registration_no', 50)->nullable();
            $table->string('phone', 100)->nullable();
            $table->string('website', 255)->nullable();
            $table->string('uen', 50)->nullable();
            $table->decimal('write_off_approval_threshold_sgd', 12, 2)->nullable();
            $table->decimal('credit_note_approval_threshold_sgd', 12, 2)->nullable();
            $table->decimal('po_approval_threshold_sgd', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
