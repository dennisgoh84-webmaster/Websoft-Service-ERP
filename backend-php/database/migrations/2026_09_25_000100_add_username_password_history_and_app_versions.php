<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add username and force_password_change_on_login to users table
        Schema::table('users', function (Blueprint $table) {
            // Username: separate from email, cannot be edited after creation
            // Nullable first to backfill with email values
            $table->string('username')->nullable()->after('email')->unique();
            // Admin-triggered force password change on next login
            $table->boolean('force_password_change_on_login')->after('must_change_password')->default(false);
        });

        // Track password history to prevent reuse (last 5 passwords per user)
        Schema::create('user_password_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->string('hashed_password');
            $table->timestampTz('set_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users');
            $table->index(['user_id', 'set_at']);
        });

        // App version history for customer visibility
        Schema::create('app_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('version')->unique(); // e.g. "1.0.0"
            $table->dateTime('release_date');
            $table->text('changelog')->nullable();
            $table->text('deployment_notes')->nullable();
            $table->boolean('is_public')->default(true);
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['is_public', 'release_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('app_versions');
        Schema::dropIfExists('user_password_history');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('force_password_change_on_login');
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
