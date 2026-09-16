<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * IMAP credentials for the two system mailboxes, alongside their
 * existing SMTP send settings -- SMTP still does the sending (OTP
 * codes, helpdesk acknowledgements; there is no way to send mail over
 * IMAP), this adds the ability to also log into the same mailbox to
 * read it, and to prove those credentials work
 * (SystemMailController::testImap()). Additive only: nothing about the
 * existing SMTP-based send path changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_mail_settings', function (Blueprint $table) {
            $table->string('imap_host', 255)->nullable();
            $table->integer('imap_port')->default(993);
            $table->string('imap_username', 255)->nullable();
            // Ciphertext, so wider than the plaintext it holds -- same treatment as the SMTP password.
            $table->text('imap_password')->nullable();
            $table->boolean('imap_use_ssl')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('system_mail_settings', function (Blueprint $table) {
            $table->dropColumn(['imap_host', 'imap_port', 'imap_username', 'imap_password', 'imap_use_ssl']);
        });
    }
};
