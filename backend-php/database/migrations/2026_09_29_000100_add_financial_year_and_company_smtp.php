<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Company Setup gains a financial year and its own mailbox
 * (Dennis, 2026-09-15).
 *
 * FINANCIAL YEAR. Until now "this Financial Year" was taken as the
 * calendar year, with a KNOWN GAP recorded in SalesDashboardService
 * saying no fiscal-year-start field existed anywhere. It does now.
 * Confirmed: Webmaster Consultancy runs 1 July - 30 June, and a
 * financial year is LABELLED BY THE CALENDAR YEAR IT ENDS IN, so
 * Jul 2026 - Jun 2027 is FY2027. Default 7 accordingly.
 *
 * COMPANY MAILBOX. Deliberately SEPARATE from the system mailbox in
 * config/websoft.php, with NO fallback between them:
 *
 *   system mail  (.env)      -> login OTP, password reset
 *   company mail (these)     -> Email Invoice / Quotation / PO /
 *                               Receipt / Statement
 *
 * Auth email runs before a company is chosen, so it cannot resolve a
 * company mailbox; and a customer-facing invoice must come from that
 * entity's own domain or it fails SPF/DKIM at the receiving server.
 * Falling back would mean silently sending an invoice from the wrong
 * domain, which is worse than a clear "not configured" error.
 *
 * The password is ENCRYPTED AT REST via the model's `encrypted` cast
 * and never returned by the API -- see App\Models\Company's $hidden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // 1-12. 7 = July, Webmaster's own financial year start.
            $table->smallInteger('financial_year_start_month')->default(7);

            $table->string('smtp_host', 255)->nullable();
            $table->integer('smtp_port')->default(587);
            $table->string('smtp_username', 255)->nullable();
            // Ciphertext, so wider than the plaintext it holds.
            $table->text('smtp_password')->nullable();
            $table->boolean('smtp_use_tls')->default(true);
            $table->string('smtp_from_email', 255)->nullable();
            $table->string('smtp_from_name', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn([
                'financial_year_start_month',
                'smtp_host', 'smtp_port', 'smtp_username', 'smtp_password',
                'smtp_use_tls', 'smtp_from_email', 'smtp_from_name',
            ]);
        });
    }
};
