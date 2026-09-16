<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Prerequisite for WhatsApp OTP (planned-work.md "WhatsApp OTP as a
 * second login factor"): there was no phone number anywhere on User,
 * so there was nothing to send a WhatsApp message to. E.164 format
 * (`+` then country code then subscriber number, digits only, e.g.
 * `+6591234567`) -- see AuthController's validation and WhatsAppSender,
 * which both assume this shape.
 *
 * Nullable and not required at signup: WhatsApp OTP is opt-in per user
 * (see the "available channels" logic in AuthController) -- a user
 * with no phone on file keeps using email OTP exactly as today.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('phone');
        });
    }
};
