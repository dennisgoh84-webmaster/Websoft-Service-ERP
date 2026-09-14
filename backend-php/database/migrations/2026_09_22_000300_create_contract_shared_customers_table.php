<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// NEW FEATURE (not a Python->PHP conversion): "Service Contract - To
// have selection of Sharing of Hours with multiple company even not
// in the related company file, When opening of Job Orders, have to
// check according to this company list included." See
// docs/backlog.md / docs/planned-work.md.
//
// A contract's own, independent list of CompanyIndividual customers
// allowed to draw down its pooled hours -- deliberately NOT the same
// as App\Models\CompanyIndividualRelationship (CompanyIndividual
// Management's own company/individual/contact relationships feature):
// a shared-hours customer need not have any relationship record with
// the contract's primary customer at all. Job Order creation
// (App\Http\Controllers\Api\JobOrderController::store) validates the
// requesting customer against the contract's own customer_id OR this
// list -- see App\Services\ContractService::customerAllowedOnContract().
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contract_shared_customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_id');
            $table->uuid('customer_id');
            $table->uuid('added_by_user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('contract_id')->references('id')->on('contracts');
            $table->foreign('customer_id')->references('id')->on('company_individuals');
            $table->foreign('added_by_user_id')->references('id')->on('users');
            $table->unique(['contract_id', 'customer_id'], 'uq_contract_shared_customer');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_shared_customers');
    }
};
