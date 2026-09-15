<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GL Types and the Currency Rate Table. Mirrors
 * backend/app/models/accounting.py's GLType and
 * backend/app/models/treasury.py's CurrencyRate.
 *
 * Also adds `accounts.gl_type_id`, which Python's Account has had all
 * along but backend-php's accounts table never carried -- the GL
 * posting conversion scoped GLType out (see App\Models\Account's
 * docblock), which left the column missing rather than merely unused.
 * Adding it here brings the schema back to parity, the same way the
 * Customer Helpdesk Portal conversion added the two foreign keys
 * earlier migrations had deferred until portal_users existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gl_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('code', 20);
            $table->string('name', 100);
            // asset|liability|equity|revenue|expense -- stored as a
            // plain string for the same reason accounts.account_type is.
            $table->string('account_type', 20);
            $table->boolean('is_active')->default(true);

            $table->foreign('company_id')->references('id')->on('companies');
            $table->unique(['company_id', 'code'], 'uq_gl_type_code');
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->uuid('gl_type_id')->nullable();
            $table->foreign('gl_type_id')->references('id')->on('gl_types');
        });

        Schema::create('currency_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('currency_code', 3);
            // Numeric(18,6) in Python -- a rate needs more scale than money.
            $table->decimal('rate_to_base', 18, 6);
            $table->date('effective_date');
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->unique(['company_id', 'currency_code', 'effective_date'], 'uq_currency_rate');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->dropForeign(['gl_type_id']);
            $table->dropColumn('gl_type_id');
        });
        Schema::dropIfExists('currency_rates');
        Schema::dropIfExists('gl_types');
    }
};
