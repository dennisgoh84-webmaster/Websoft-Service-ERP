<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI Assistant slice 2 (2026-09-15): the assistant gets a name and a
 * face. Dennis: "design a websoft AI Girl" -- the image is generated
 * outside the system (an /imagine prompt is in docs/planned-work.md
 * #12) and uploaded here as a data URL, the same way the company logo
 * is stored. The chat itself needs no new table: every exchange is an
 * ai_interactions row with feature `chat`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->string('assistant_name', 40)->default('Websoft AI');
            $table->text('assistant_avatar')->nullable(); // data:image/... URL, <= ~300 KB
        });
    }

    public function down(): void
    {
        Schema::table('ai_settings', function (Blueprint $table) {
            $table->dropColumn(['assistant_name', 'assistant_avatar']);
        });
    }
};
