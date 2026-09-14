<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/company_individuals.py -- CompanyIndividual
// Management (Customer/Supplier master), Contacts, Branches, and the
// CompanyIndividualGroup tag. See that file's docstring for the full
// field-by-field rationale (Odoo Contacts card mapping, PDPA consent,
// data-expiry archival, merged customer/supplier role flags).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('company_individual_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
        });

        Schema::create('company_individuals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('customer_type', 20)->default('company'); // individual|company
            $table->string('name');
            $table->uuid('customer_group_id')->nullable();
            $table->string('legacy_customer_code', 50)->nullable();
            $table->string('contact_person')->nullable();
            $table->string('uen', 20)->nullable();
            $table->string('gst_registration_no', 50)->nullable();
            $table->string('billing_email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('mobile', 50)->nullable();
            $table->string('website')->nullable();
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('address_city', 100)->nullable();
            $table->string('address_state', 100)->nullable();
            $table->string('address_postal_code', 20)->nullable();
            $table->string('address_country', 100)->nullable();
            $table->string('tags')->nullable();
            $table->string('industry_code', 20)->nullable();
            $table->boolean('exclude_auto_sent')->default(false);
            $table->text('terms_and_conditions')->nullable();
            $table->text('memo')->nullable();
            $table->text('billing_notes')->nullable();
            $table->integer('payment_terms_days')->nullable();
            $table->boolean('is_customer')->default(true);
            $table->boolean('is_supplier')->default(false);
            $table->boolean('pdpa_consent_given')->default(false);
            $table->timestampTz('pdpa_consent_at')->nullable();
            $table->text('pdpa_agreement_document')->nullable();
            $table->date('data_expiry_date')->nullable();
            $table->boolean('is_archived')->default(false);
            $table->timestampTz('archived_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('customer_group_id')->references('id')->on('company_individual_groups');
            $table->index('name');
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 50)->nullable();
            $table->string('direct_line', 50)->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreign('customer_id')->references('id')->on('company_individuals');
        });

        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_id');
            $table->string('branch_code', 50)->nullable();
            $table->string('branch_name');
            $table->string('address_line1')->nullable();
            $table->string('address_line2')->nullable();
            $table->string('address_city', 100)->nullable();
            $table->string('address_state', 100)->nullable();
            $table->string('address_postal_code', 20)->nullable();
            $table->string('address_country', 100)->nullable();
            $table->string('phone', 50)->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreign('customer_id')->references('id')->on('company_individuals');
        });

        Schema::create('company_individual_relationships', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('from_customer_id');
            $table->uuid('to_customer_id')->nullable();
            $table->uuid('to_contact_id')->nullable();
            $table->string('relationship_type');
            $table->text('note')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->uuid('created_by_user_id')->nullable();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('from_customer_id')->references('id')->on('company_individuals');
            $table->foreign('to_customer_id')->references('id')->on('company_individuals');
            $table->foreign('to_contact_id')->references('id')->on('contacts');
            $table->foreign('created_by_user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('company_individual_relationships');
        Schema::dropIfExists('branches');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('company_individuals');
        Schema::dropIfExists('company_individual_groups');
    }
};
