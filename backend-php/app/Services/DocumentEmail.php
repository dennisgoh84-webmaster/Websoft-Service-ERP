<?php

namespace App\Services;

use App\Models\Company;

/**
 * Shared "Email this document" helper (2026-09-12), used by every
 * document type that offers an Email button: Purchase Order, Sales
 * Quotation, Sales Invoice, Receipt Voucher, Payment Voucher, Service
 * Record, Statement of Accounts. Each controller builds its own .docx
 * (via App\Services\DocxForms) and calls this rather than repeating the
 * PDF-conversion + SMTP-send boilerplate seven times over.
 *
 * Direct conversion of backend/app/services/document_email.py,
 * including its status-code mapping: a PDF-conversion failure or an
 * unconfigured mailer is a 422 (something about this install needs
 * fixing before the button can work), an SMTP failure is a 502 (the
 * request was fine, the upstream mail server was not).
 *
 * SENDS FROM THE COMPANY'S OWN MAILBOX (Dennis, 2026-09-15), not the
 * system one, and never falls back to it. These are customer-facing
 * documents, so they must come from that entity's own domain -- see
 * App\Services\Mailer's docblock for why falling back would be worse
 * than failing. `backend/` sends all of these from the single system
 * mailbox, so this is a DELIBERATE DIVERGENCE, recorded in
 * docs/php-conversion-plan.md.
 */
class DocumentEmail
{
    public static function sendDocumentEmail(
        Company $company,
        string $toEmail,
        string $subject,
        string $bodyText,
        string $docxBytes,
        string $filenameStem,
    ): void {
        try {
            $pdfBytes = PdfConvert::docxBytesToPdf($docxBytes);
        } catch (PdfConversionError $e) {
            throw new DocumentEmailError($e->getMessage(), 422, $e);
        }

        try {
            Mailer::sendAs($company, $toEmail, $subject, $bodyText, "{$filenameStem}.pdf", $pdfBytes);
        } catch (MailerNotConfiguredException $e) {
            throw new DocumentEmailError($e->getMessage(), 422, $e);
        } catch (MailerException $e) {
            throw new DocumentEmailError($e->getMessage(), 502, $e);
        }
    }
}

/**
 * Wraps PdfConversionError/MailerNotConfiguredException/MailerException
 * so a controller needs only one catch clause; `statusCode` says which
 * HTTP status the controller should raise.
 */
class DocumentEmailError extends \RuntimeException
{
    public function __construct(string $message, public readonly int $statusCode, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
