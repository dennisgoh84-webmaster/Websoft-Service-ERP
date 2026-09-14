<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Mirrors backend/app/models/contracts.py's Contract and
// ContractProduct -- see that file's docstring for the SRV-001..018
// design and why contracted_minutes/consumed_minutes are stored in
// minutes, not hours (avoids float rounding issues with SRV-007's
// 15-minute rounding rule).
//
// NOT included yet (see docs/php-conversion-plan.md): ExcessUsageRecord,
// which references service_records (not converted) -- it belongs to
// the Excess Usage phase that follows Service Records.
// ExpiredHoursRecord IS included here (SRV-005): it only depends on
// contracts, so it doesn't need to wait.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('customer_id');
            $table->string('contract_number', 50);
            $table->string('status', 20)->default('draft'); // draft|active|exceeded|expired|renewed
            $table->string('contract_kind', 20)->default('service_support'); // service_support|annual|ad_hoc
            $table->integer('contracted_minutes');
            $table->integer('consumed_minutes')->default(0);
            $table->decimal('contract_value_sgd', 12, 2);
            $table->decimal('hourly_rate_sgd', 10, 2)->nullable();
            $table->uuid('sales_staff_id')->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->uuid('renewed_from_contract_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('activated_at')->nullable();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('customer_id')->references('id')->on('company_individuals');
            $table->foreign('sales_staff_id')->references('id')->on('users');
            $table->index('contract_number');
        });

        // Self-referencing FK added separately, after the table (and
        // its primary key) exists -- Laravel's Postgres grammar orders
        // a create-table blueprint's own ADD PRIMARY KEY statement
        // after its ADD FOREIGN KEY statements, so a self-reference
        // declared inline fails ("no unique constraint matching given
        // keys") because the PK isn't there yet when it runs.
        Schema::table('contracts', function (Blueprint $table) {
            $table->foreign('renewed_from_contract_id')->references('id')->on('contracts');
        });

        // SRV-002/012's 10-hour minimum applies only to service_support
        // contracts; an annual (term-only) contract has no hours at
        // all. Added via raw SQL -- Laravel's schema builder has no
        // fluent CHECK-constraint helper.
        DB::statement("alter table contracts add constraint ck_contract_minimum_hours check (contract_kind <> 'service_support' or contracted_minutes >= 600)");
        DB::statement('alter table contracts add constraint ck_contract_consumed_non_negative check (consumed_minutes >= 0)');

        Schema::create('contract_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_id');
            $table->uuid('product_id');
            $table->string('license_type', 20)->nullable(); // local|rdp|web
            $table->integer('number_of_licenses')->nullable();

            $table->foreign('contract_id')->references('id')->on('contracts');
            $table->foreign('product_id')->references('id')->on('products');
            $table->unique(['contract_id', 'product_id'], 'uq_contract_product');
        });

        Schema::create('expired_hours_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('contract_id');
            $table->integer('expired_minutes');
            $table->timestampTz('recorded_at')->useCurrent();

            $table->foreign('contract_id')->references('id')->on('contracts');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expired_hours_records');
        Schema::dropIfExists('contract_products');
        Schema::dropIfExists('contracts');
    }
};
