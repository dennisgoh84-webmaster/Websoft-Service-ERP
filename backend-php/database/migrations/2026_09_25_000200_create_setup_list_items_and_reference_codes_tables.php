<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Setup Lists and Reference Codes. Mirrors
 * backend/app/models/setup.py's SetupListItem and
 * backend/app/models/reference_codes.py's ReferenceCode.
 *
 * `setup_list_items` is deliberately NOT company-scoped: a country's
 * name does not differ per company, so every company shares one global
 * list per list_type. It is one of only two global tables in the system
 * (announcements is the other).
 *
 * This migration also adds the two foreign keys earlier migrations
 * deferred until `reference_codes` existed --
 * `products.default_reference_code_id` and
 * `quotation_lines.reference_code_id` -- both of which have carried the
 * column without its constraint since their own migrations said so.
 * Same pattern the Customer Helpdesk Portal used for login_otps and
 * incidents once portal_users existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('setup_list_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // nationality|country|state|area_code|currency|industry
            $table->string('list_type', 20);
            $table->string('code', 20);
            $table->string('name', 150);
            // A plain string reference to another row's code (a State's
            // owning Country), NOT a foreign key -- Python keeps it soft
            // on purpose, since the parent can be in a different
            // list_type. Preserved rather than tightened.
            $table->string('parent_code', 20)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);

            $table->unique(['list_type', 'code'], 'uq_setup_list_item_code');
        });

        Schema::create('reference_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('account_id');
            $table->string('code', 50);
            $table->string('name', 200);
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('account_id')->references('id')->on('accounts');
            $table->unique(['company_id', 'code'], 'uq_reference_code');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreign('default_reference_code_id')->references('id')->on('reference_codes');
        });

        Schema::table('quotation_lines', function (Blueprint $table) {
            $table->foreign('reference_code_id')->references('id')->on('reference_codes');
        });
    }

    public function down(): void
    {
        Schema::table('quotation_lines', function (Blueprint $table) {
            $table->dropForeign(['reference_code_id']);
        });
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['default_reference_code_id']);
        });
        Schema::dropIfExists('reference_codes');
        Schema::dropIfExists('setup_list_items');
    }
};
