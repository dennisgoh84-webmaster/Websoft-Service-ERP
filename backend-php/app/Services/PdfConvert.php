<?php

namespace App\Services;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Convert a generated .docx form (see DocxForms) to PDF bytes, for
 * "Email PO" (2026-09-12): a real send needs a real PDF attachment, and
 * the browser's own Print -> Save as PDF (used by every *PrintPage.tsx)
 * isn't available on the server.
 *
 * Direct conversion of backend/app/services/pdf_convert.py, and it
 * keeps that module's design decision deliberately: rather than a
 * second, separately-maintained PDF layout, this shells out to
 * LibreOffice headless to convert the exact same .docx bytes already
 * built for the "Word" export button -- one template, two output
 * formats, so they can never drift apart. This adds LibreOffice as a
 * system dependency on whatever machine runs the backend
 * (`apt install libreoffice-core` or similar); see DEV_SETUP.md.
 */
class PdfConvert
{
    public static function docxBytesToPdf(string $docxBytes, int $timeoutSeconds = 30): string
    {
        $tmp = sys_get_temp_dir().'/websoft-pdf-'.bin2hex(random_bytes(8));
        if (! @mkdir($tmp, 0700, true) && ! is_dir($tmp)) {
            throw new PdfConversionError('PDF conversion failed: could not create a temporary directory.');
        }

        try {
            // A random name per call, plus each call getting its own tempdir as
            // -env:UserInstallation, avoids soffice's profile-lock contention if
            // two conversions ever land at the same moment.
            $docxPath = $tmp.'/'.bin2hex(random_bytes(16)).'.docx';
            file_put_contents($docxPath, $docxBytes);
            $profileDir = $tmp.'/profile';

            $process = new Process([
                'soffice',
                '--headless',
                '--norestore',
                "-env:UserInstallation=file://{$profileDir}",
                '--convert-to',
                'pdf',
                '--outdir',
                $tmp,
                $docxPath,
            ]);
            $process->setTimeout($timeoutSeconds);

            try {
                $process->run();
            } catch (ProcessTimedOutException $e) {
                throw new PdfConversionError('PDF conversion timed out.', previous: $e);
            }

            // Symfony's Process reports a missing binary as exit code 127 with
            // "command not found" on stderr, rather than throwing the way
            // Python's subprocess raises FileNotFoundError -- so the
            // not-installed case is detected here instead of in a catch block.
            if (! $process->isSuccessful() && self::looksLikeMissingBinary($process)) {
                throw new PdfConversionError(
                    'PDF conversion is not available: LibreOffice (soffice) is not installed '
                    .'on this server. See DEV_SETUP.md.'
                );
            }

            $pdfPath = preg_replace('/\.docx$/', '.pdf', $docxPath);
            if (! $process->isSuccessful() || ! is_file($pdfPath)) {
                $stderr = trim($process->getErrorOutput());
                throw new PdfConversionError('PDF conversion failed: '.($stderr !== '' ? $stderr : 'unknown error'));
            }

            return (string) file_get_contents($pdfPath);
        } finally {
            self::removeDirectory($tmp);
        }
    }

    private static function looksLikeMissingBinary(Process $process): bool
    {
        if ($process->getExitCode() !== 127) {
            return false;
        }

        return str_contains(strtolower($process->getErrorOutput()), 'not found');
    }

    private static function removeDirectory(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($dir);
    }
}

class PdfConversionError extends \RuntimeException {}
