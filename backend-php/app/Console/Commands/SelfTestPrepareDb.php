<?php

namespace App\Console\Commands;

use App\Models\CompanyModule;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Wipe and rebuild the self-test program's own database (docs/self-test.md).
 *
 * The self-test keys real records in through every screen, so it runs
 * against a throwaway database, built fresh each run: migrations, then
 * the ordinary seeder, and nothing else -- whatever a screen needs, the
 * test keys in itself.
 *
 * `migrate:fresh` drops every table, so this refuses to touch any
 * database whose name does not end in "_selftest". That one rule is
 * what keeps a mistyped DB_DATABASE from wiping real data.
 */
class SelfTestPrepareDb extends Command
{
    public const SUFFIX = '_selftest';

    protected $signature = 'selftest:prepare-db';

    protected $description = 'Rebuild the self-test database from scratch (only a database named *_selftest)';

    public function handle(): int
    {
        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        if (! str_ends_with($database, self::SUFFIX)) {
            $this->error("Refusing: the database is \"{$database}\". The self-test only ever rebuilds a database whose name ends in \"".self::SUFFIX.'", so real data can never be wiped by it.');

            return self::FAILURE;
        }

        $this->createIfMissing($connection, $database);

        $this->info("Rebuilding {$database}: migrations, then the seeder.");
        $status = Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true], $this->output);
        if ($status !== 0) {
            return self::FAILURE;
        }

        // Every module on, paid add-ons included: the self-test walks
        // every screen, and a switched-off module hides its screens.
        // (Switching modules is Central Command's job, not a screen
        // here, so there is nothing to key in for it.)
        $switched = CompanyModule::where('enabled', false)->update(['enabled' => true]);
        $this->info("Switched on {$switched} module(s) that are off by default.");

        return self::SUCCESS;
    }

    /** A fresh machine has no self-test database yet; make it through the server's own "postgres" database. */
    private function createIfMissing(string $connection, string $database): void
    {
        $admin = config("database.connections.{$connection}");
        $admin['database'] = 'postgres';
        config(['database.connections.selftest_admin' => $admin]);

        $exists = DB::connection('selftest_admin')->select('select 1 from pg_database where datname = ?', [$database]);
        if ($exists === []) {
            $this->info("Creating database {$database}.");
            DB::connection('selftest_admin')->statement('create database "'.str_replace('"', '', $database).'"');
        }
        DB::purge('selftest_admin');
    }
}
