<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Exceptions\ApiException;
use App\Models\Company;
use App\Services\DocumentEmail;
use App\Services\DocumentEmailError;
use Illuminate\Http\Response;

/**
 * The two halves every "Word export" / "Email X" endpoint repeats: the
 * .docx download response, and turning a DocumentEmailError into the
 * HTTP status the Python routers raise.
 *
 * In backend/ this boilerplate is written out inline in each router
 * (`StreamingResponse(...)` plus a two-line
 * `except document_email.DocumentEmailError` clause); collapsing it
 * into one trait here changes no behaviour -- the media type, the
 * Content-Disposition filename and the 422/502 status mapping are
 * identical -- it just avoids repeating it across six controllers.
 */
trait SendsDocuments
{
    private const DOCX_MEDIA_TYPE = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    protected function docxResponse(string $bytes, string $filename): Response
    {
        return response($bytes, 200, [
            'Content-Type' => self::DOCX_MEDIA_TYPE,
            'Content-Disposition' => "attachment; filename={$filename}",
        ]);
    }

    /**
     * @return array{sent: bool, to: string} the Python routers' own response body
     */
    protected function emailDocument(
        ?Company $company,
        string $toEmail,
        string $subject,
        string $bodyText,
        string $docxBytes,
        string $filenameStem,
    ): array {
        // Python tolerates a missing Company when interpolating its
        // name, but a document email has to be SENT FROM one, so a
        // missing company is a clear 422 rather than a TypeError.
        if ($company === null) {
            throw new ApiException(422, 'No company is set up to send this from.');
        }

        try {
            // Sent from THIS company's own mailbox, never the system one
            // -- see App\Services\Mailer.
            DocumentEmail::sendDocumentEmail($company, $toEmail, $subject, $bodyText, $docxBytes, $filenameStem);
        } catch (DocumentEmailError $e) {
            throw new ApiException($e->statusCode, $e->getMessage());
        }

        return ['sent' => true, 'to' => $toEmail];
    }

    /**
     * Python interpolates a possibly-missing Company as
     * `company.name if company else ''` in every subject/sign-off.
     */
    protected function companyName(mixed $company): string
    {
        return (string) ($company?->name ?? '');
    }
}
