<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * On-disk storage for Mobile Web App attachments, and the chop-photo
 * watermark. Mirrors backend/app/services/file_storage.py, including
 * its directory layout so the two backends can read each other's
 * files during the parallel run:
 *
 *   <uploads_dir>/<company_id>/<service_record_id>/<attachment_id>.<ext>
 *
 * Note this is a DIFFERENT layout from DocumentService's
 * <uploads_dir>/<company_id>/docs/<entity_type>/<entity_id>/, because
 * Python keeps the two apart. Preserved rather than unified.
 *
 * The watermark is a GD port of Python's PIL implementation. GD is a
 * bundled PHP extension, so no new dependency was needed; it is
 * compiled here with JPEG and FreeType support, and the same DejaVu
 * font Python asks for is present, so the output matches in layout and
 * content rather than only in spirit.
 */
class MobileFileStorage
{
    private const FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf';

    public static function uploadDirFor(string $companyId, string $serviceRecordId): string
    {
        $dir = rtrim((string) config('websoft.uploads_dir'), '/')."/{$companyId}/{$serviceRecordId}";
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /** Save raw bytes to disk. Returns the stored filename. */
    public static function saveFile(
        string $companyId,
        string $serviceRecordId,
        string $attachmentId,
        string $originalFilename,
        string $data,
    ): string {
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $storedName = $attachmentId.($ext !== '' ? '.'.$ext : '');
        file_put_contents(self::uploadDirFor($companyId, $serviceRecordId).'/'.$storedName, $data);

        return $storedName;
    }

    public static function filePath(string $companyId, string $serviceRecordId, string $storedFilename): ?string
    {
        $path = rtrim((string) config('websoft.uploads_dir'), '/')
            ."/{$companyId}/{$serviceRecordId}/{$storedFilename}";

        return is_file($path) ? $path : null;
    }

    /**
     * Stamp a visible watermark on a chop photo with the Service Record
     * number and capture time, returned as JPEG bytes.
     *
     * Two marks, exactly as Python draws them: a semi-transparent black
     * strip along the bottom carrying "SR-NUMBER | timestamp" in white,
     * and the SR number again, larger and fainter, across the centre.
     * Together they tie the photo to this one Service Record, which is
     * what makes the confirmed no-reuse rule enforceable rather than a
     * request.
     */
    public static function watermarkChopPhoto(string $imageBytes, string $serviceRecordNumber, Carbon $timestamp): string
    {
        $img = @imagecreatefromstring($imageBytes);
        if ($img === false) {
            throw new \RuntimeException('Chop photo is not a readable image');
        }

        $width = imagesx($img);
        $height = imagesy($img);
        imagealphablending($img, true);

        $text = $serviceRecordNumber.'  |  '.$timestamp->format('Y-m-d H:i:s');
        // Font size relative to image width, same ratios as Python.
        $fontSize = max(16, intdiv($width, 30));
        $useTtf = is_file(self::FONT) && function_exists('imagettfbbox');

        [$textW, $textH] = self::textSize($text, $fontSize, $useTtf);
        $stripH = $textH + 20;
        $stripY = $height - $stripH;

        // GD alpha runs 0 (opaque) to 127 (transparent), the inverse of
        // PIL's 0-255, so each alpha below is converted rather than copied.
        $strip = imagecolorallocatealpha($img, 0, 0, 0, self::alpha(160));
        imagefilledrectangle($img, 0, $stripY, $width, $height, $strip);

        $white = imagecolorallocatealpha($img, 255, 255, 255, self::alpha(230));
        self::drawText($img, $text, intdiv($width - $textW, 2), $stripY + 10, $fontSize, $white, $useTtf);

        $diagSize = max(24, intdiv($width, 15));
        [$diagW, $diagH] = self::textSize($serviceRecordNumber, $diagSize, $useTtf);
        $faint = imagecolorallocatealpha($img, 255, 255, 255, self::alpha(80));
        self::drawText(
            $img,
            $serviceRecordNumber,
            intdiv($width - $diagW, 2),
            intdiv($height - $diagH, 2) - intdiv($stripH, 2),
            $diagSize,
            $faint,
            $useTtf,
        );

        ob_start();
        imagejpeg($img, null, 90);
        $out = (string) ob_get_clean();
        imagedestroy($img);

        return $out;
    }

    /** PIL alpha (0 transparent..255 opaque) -> GD alpha (0 opaque..127 transparent). */
    private static function alpha(int $pilAlpha): int
    {
        return (int) round((255 - $pilAlpha) * 127 / 255);
    }

    /** @return array{0:int,1:int} */
    private static function textSize(string $text, int $fontSize, bool $useTtf): array
    {
        if ($useTtf) {
            $box = imagettfbbox($fontSize, 0, self::FONT, $text);

            return [abs($box[2] - $box[0]), abs($box[7] - $box[1])];
        }

        // Fallback to GD's built-in font, as Python falls back to
        // PIL's default when the TrueType font is missing.
        return [imagefontwidth(5) * strlen($text), imagefontheight(5)];
    }

    private static function drawText(\GdImage $img, string $text, int $x, int $y, int $fontSize, int $color, bool $useTtf): void
    {
        if ($useTtf) {
            // imagettftext takes the text BASELINE, where PIL takes the
            // top-left corner, so the height is added back here.
            [, $h] = self::textSize($text, $fontSize, true);
            imagettftext($img, $fontSize, 0, $x, $y + $h, $color, self::FONT, $text);

            return;
        }
        imagestring($img, 5, $x, $y, $text, $color);
    }

    public static function deleteFile(string $companyId, string $serviceRecordId, string $storedFilename): void
    {
        $path = self::filePath($companyId, $serviceRecordId, $storedFilename);
        if ($path !== null) {
            @unlink($path);
        }
    }
}
