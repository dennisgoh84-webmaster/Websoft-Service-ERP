<?php

namespace App\Services;

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
 */
class DocumentEmail
{
    public static function sendDocumentEmail(
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
            Mailer::send($toEmail, $subject, $bodyText, "{$filenameStem}.pdf", $pdfBytes);
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
