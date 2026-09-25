<?php

use App\Services\Numbering;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Prospect / Leads (Dennis, 2026-09-26): a prospect is one sales
 * opportunity for a Company / Individual. Its activities are logged
 * against it (mostly from the Mobile App), it can carry several
 * quotations, and every invoice raised from a contract one of those
 * quotations became is tied back to it -- so the prospect can report
 * its estimated, quoted, billed, paid and outstanding amounts.
 *
 * Replaces the "CRM" module key with "prospects" in Module Control,
 * keeping every company's enabled flag and every group's authority.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prospects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('prospect_number', 30);
            $table->uuid('customer_id');
            $table->string('title');
            $table->string('source', 100)->nullable();
            $table->string('status', 20)->default('open');
            $table->decimal('estimated_value_sgd', 14, 2)->nullable();
            $table->date('expected_close_date')->nullable();
            $table->uuid('salesperson_user_id')->nullable();
            $table->text('notes')->nullable();
            $table->text('lost_reason')->nullable();
            $table->uuid('created_by_user_id')->nullable();
            $table->uuid('last_edited_by_user_id')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();

            $table->foreign('company_id')->references('id')->on('companies');
            $table->foreign('customer_id')->references('id')->on('company_individuals');
            $table->foreign('salesperson_user_id')->references('id')->on('users');
            $table->foreign('created_by_user_id')->references('id')->on('users');
            $table->foreign('last_edited_by_user_id')->references('id')->on('users');
            $table->unique(['company_id', 'prospect_number']);
            $table->index(['company_id', 'customer_id']);
            $table->index(['company_id', 'salesperson_user_id']);
        });

        // The activity table's updated_at had no database default (its
        // useCurrentOnUpdate() is MySQL-only), so it relied on Eloquent
        // writing the app's Singapore clock into a UTC column. It now
        // defaults like created_at does.
        DB::statement('ALTER TABLE prospect_activities ALTER COLUMN updated_at SET DEFAULT CURRENT_TIMESTAMP');

        foreach (['prospect_activities', 'quotations', 'invoices'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->uuid('prospect_id')->nullable();
                $table->foreign('prospect_id')->references('id')->on('prospects');
                $table->index('prospect_id');
            });
        }

        // Activities logged before prospects existed hung off the
        // Company / Individual directly. Give each such company one open
        // prospect and move its activities under it, so none drop out of
        // the new screens.
        $groups = DB::table('prospect_activities')
            ->select('company_id', 'customer_id')
            ->groupBy('company_id', 'customer_id')
            ->get();
        foreach ($groups as $g) {
            DB::transaction(function () use ($g) {
                $first = DB::table('prospect_activities')
                    ->where('company_id', $g->company_id)->where('customer_id', $g->customer_id)
                    ->orderBy('created_at')->first();
                $name = DB::table('company_individuals')->where('id', $g->customer_id)->value('name');
                $id = (string) Str::uuid();
                DB::table('prospects')->insert([
                    'id' => $id,
                    'company_id' => $g->company_id,
                    'prospect_number' => Numbering::next($g->company_id, 'prospect'),
                    'customer_id' => $g->customer_id,
                    'title' => ($name ?? 'Prospect').' (activities logged before Prospect / Leads)',
                    'status' => 'open',
                    'salesperson_user_id' => $first->created_by_user_id,
                    'created_by_user_id' => $first->created_by_user_id,
                    'created_at' => $first->created_at ?? Carbon::now('UTC'),
                    'updated_at' => Carbon::now('UTC'),
                ]);
                DB::table('prospect_activities')
                    ->where('company_id', $g->company_id)->where('customer_id', $g->customer_id)
                    ->update(['prospect_id' => $id]);
            });
        }

        // Module Control: "crm" becomes "prospects". The new key goes in
        // first so the foreign keys from company_modules and
        // group_module_authorities can be moved across before the old
        // key is removed.
        DB::table('modules')->insertOrIgnore([
            'key' => 'prospects',
            'name' => 'Prospect / Leads',
            'description' => 'Prospects for each Company / Individual, their activities (logged on the Mobile App), their quotations, and what has been billed and paid against them.',
            'is_built' => true,
        ]);
        DB::table('company_modules')->where('module_key', 'crm')->update(['module_key' => 'prospects']);
        DB::table('group_module_authorities')->where('module_key', 'crm')->update(['module_key' => 'prospects']);
        DB::table('modules')->where('key', 'crm')->delete();
    }

    public function down(): void
    {
        DB::table('modules')->insertOrIgnore(['key' => 'crm', 'name' => 'CRM', 'is_built' => true]);
        DB::table('company_modules')->where('module_key', 'prospects')->update(['module_key' => 'crm']);
        DB::table('group_module_authorities')->where('module_key', 'prospects')->update(['module_key' => 'crm']);
        DB::table('modules')->where('key', 'prospects')->delete();

        DB::statement('ALTER TABLE prospect_activities ALTER COLUMN updated_at DROP DEFAULT');

        foreach (['invoices', 'quotations', 'prospect_activities'] as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropForeign(['prospect_id']);
                $table->dropIndex(['prospect_id']);
                $table->dropColumn('prospect_id');
            });
        }
        Schema::dropIfExists('prospects');
    }
};
