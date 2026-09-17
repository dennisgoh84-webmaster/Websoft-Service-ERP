<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill username from email (take part before @)
        DB::statement('UPDATE users SET username = SPLIT_PART(email, \'@\', 1)');

        // Make username not nullable after backfill
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->change();
        });
    }

    public function down(): void
    {
        // Rollback by making username nullable
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->change();
        });
    }
};
