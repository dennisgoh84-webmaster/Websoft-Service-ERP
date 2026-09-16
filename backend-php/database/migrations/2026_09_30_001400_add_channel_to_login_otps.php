<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which channel this OTP was actually sent on -- email or whatsapp.
 * Defaults to 'email': every row created before this migration was
 * sent by Mailer (the only channel that has ever existed), so backing
 * that in as the default keeps existing unconsumed OTPs valid instead
 * of leaving them with a NULL channel meaning nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_otps', function (Blueprint $table) {
            $table->string('channel', 20)->default('email');
        });
    }

    public function down(): void
    {
        Schema::table('login_otps', function (Blueprint $table) {
            $table->dropColumn('channel');
        });
    }
};
