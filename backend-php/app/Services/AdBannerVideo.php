<?php

namespace App\Services;

use Illuminate\Support\Str;

/**
 * On-disk storage for the promo ad banner's uploaded video
 * (App\Models\AdBannerSettings). One file at a time -- there is only
 * ever one video slot -- stored under `uploads_dir`/ad_banner, named
 * by a random id rather than the upload's own filename so a new
 * upload never collides with (or gets confused for) the one it
 * replaces, and so the served URL changes on every upload, which
 * busts any browser cache of the old clip for free.
 *
 * Deliberately simpler than MobileFileStorage: this isn't scoped to a
 * company or record, there's only ever one current file, and nothing
 * here needs to keep old versions around.
 */
class AdBannerVideo
{
    private static function dir(): string
    {
        $dir = rtrim((string) config('websoft.uploads_dir'), '/').'/ad_banner';
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        return $dir;
    }

    /** Save raw bytes to disk. Returns the stored filename. */
    public static function save(string $originalFilename, string $data): string
    {
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        $storedName = ((string) Str::uuid()).($ext !== '' ? '.'.$ext : '');
        file_put_contents(self::dir().'/'.$storedName, $data);

        return $storedName;
    }

    public static function path(string $storedFilename): ?string
    {
        $path = self::dir().'/'.$storedFilename;

        return is_file($path) ? $path : null;
    }

    public static function delete(string $storedFilename): void
    {
        $path = self::path($storedFilename);
        if ($path !== null) {
            @unlink($path);
        }
    }
}
