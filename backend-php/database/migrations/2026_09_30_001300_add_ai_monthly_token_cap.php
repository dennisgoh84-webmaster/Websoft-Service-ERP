<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A monthly spending cap for the AI Assistant (Dennis, 2026-09-16,
 * settling decision 12.2). In TOKENS, not SGD -- see
 * App\Services\Ai\AiBudget's docblock for why a dollar figure isn't
 * the honest thing to build here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->integer('monthly_token_cap')->nullable(); // null = no cap
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn('monthly_token_cap');
        });
    }
};
