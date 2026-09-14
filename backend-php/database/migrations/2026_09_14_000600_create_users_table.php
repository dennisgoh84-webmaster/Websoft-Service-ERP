<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/core.py's User. Note: `role` is the small
// fixed named-responsibility enum (owner/service_lead/sales_manager/
// support_engineer/finance) -- Group Authority itself lives on
// UserCompanyAccess.group_id, not here. See that model's docstring.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('email')->unique();
            $table->string('hashed_password');
            $table->string('full_name');
            $table->string('role', 30); // owner|service_lead|sales_manager|support_engineer|finance
            $table->text('photo')->nullable();
            $table->boolean('must_change_password')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
        });

        Schema::create('user_company_access', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('company_id');
            $table->uuid('group_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users');
            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('group_id')->references('id')->on('groups');
            $table->unique(['user_id', 'company_id'], 'uq_user_company');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_company_access');
        Schema::dropIfExists('users');
    }
};
