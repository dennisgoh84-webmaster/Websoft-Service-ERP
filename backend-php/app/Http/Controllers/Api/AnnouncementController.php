<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\AdBannerSettings;
use App\Models\Announcement;
use App\Services\AdBannerVideo;
use App\Services\Audit;
use App\Services\Authority;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Platform announcements + the promo video shown on the ad banner
 * (Login page and every page after signing in -- see
 * frontend/src/components/PromoVideoPanel.tsx). Mirrors
 * backend/app/routers/announcements.py 1:1 except for the video slot
 * split and upload support, which postdate the Python backend. The
 * announcements themselves are global, not company-scoped -- see
 * App\Models\Announcement's docblock.
 *
 * The video is NOT global in the same sense any more: two independent
 * settings, one per AdBannerSettings::SLOT_* (`login`, `app`), split
 * 2026-09-16 at Dennis's direct request -- each is either an external
 * URL or an uploaded file (see that model's docblock).
 *
 * GET /public/{slot} and GET /video/{filename} are the unauthenticated
 * endpoints here (mirrors Company Setup's GET
 * /api/companies/public-branding): the Login page needs its slot
 * before anyone has signed in, and the banner shown after signing in
 * reads its own slot the same way rather than an authenticated copy,
 * since the content is non-sensitive.
 *
 * Confirmed 2026-09-12: Save = live immediately -- there is no
 * separate draft/publish step, same as every other admin screen in
 * this system (Company Setup, Module Control, Tax Types, ...).
 *
 * Central Command can push a video URL for either slot (2026-09-16,
 * Dennis: "this settings should be available from central command to
 * push out also") -- see App\Services\ClientDbService::pushVideoUrl()
 * in that separate repository. A push is necessarily URL-only: there
 * is no mechanism to transfer an uploaded file's bytes to a remote
 * client's server, only to write a row into its database, so pushing
 * a URL replaces whatever a client had uploaded locally for that slot,
 * the same as setting one here would.
 */
class AnnouncementController extends Controller
{
    private const MODULE = 'core_administration';

    /** 100 MB, in KB (Laravel's file `max:` rule) -- generous for a short promo clip, well under nginx's 500m body limit. */
    private const MAX_VIDEO_KB = 102400;

    /**
     * Fixed, arbitrary UUIDs standing in for each slot's settings row
     * in the audit trail, since AuditLogEntry::$entity_id is a UUID
     * but these rows are keyed by slot. `login` keeps the id the
     * single pre-split setting always used (Python built the same
     * value with uuid.UUID(int=1)); `app` is a new id, chosen to not
     * collide with the ones SystemMailController/AiAssistantController
     * already use (...0002-0004).
     */
    private const SETTINGS_AUDIT_IDS = [
        AdBannerSettings::SLOT_LOGIN => '00000000-0000-0000-0000-000000000001',
        AdBannerSettings::SLOT_APP => '00000000-0000-0000-0000-000000000005',
    ];

    private function slotOrFail(string $slot): void
    {
        if (! in_array($slot, AdBannerSettings::SLOTS, true)) {
            throw new ApiException(404, 'No such ad banner slot.');
        }
    }

    private function getOrCreateSettings(string $slot): AdBannerSettings
    {
        $this->slotOrFail($slot);
        $settings = AdBannerSettings::find($slot);
        if (! $settings) {
            $settings = AdBannerSettings::create(['slot' => $slot, 'video_url' => null]);
        }

        return $settings;
    }

    private function announcementOr404(string $announcementId): Announcement
    {
        $announcement = Announcement::find($announcementId);
        if (! $announcement) {
            throw new ApiException(404, 'Announcement not found');
        }

        return $announcement;
    }

    private function present(Announcement $a): array
    {
        return [
            'id' => $a->id,
            'tag' => $a->tag,
            'text' => $a->text,
            'sort_order' => $a->sort_order,
            'is_active' => $a->is_active,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    /** Unauthenticated: one slot's video URL plus only the ACTIVE announcements (shared across slots), already ordered. */
    public function publicAdBanner(string $slot)
    {
        $settings = $this->getOrCreateSettings($slot);
        $items = Announcement::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'video_url' => $this->effectiveVideoUrl($settings),
            'items' => $items->map(fn (Announcement $a) => $this->present($a))->all(),
        ]);
    }

    public function getSettings(Request $request, string $slot)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        return response()->json($this->presentSettings($this->getOrCreateSettings($slot)));
    }

    /**
     * Sets (or, with an omitted/null body, clears) an external video
     * URL for one slot. Also clears any uploaded file for that SAME
     * slot only -- the other slot is untouched -- only one of the two
     * is ever live per slot, see AdBannerSettings's docblock --
     * deleting it from disk once the row itself is safely updated.
     */
    public function updateSettings(Request $request, string $slot)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $data = $request->validate(['video_url' => 'sometimes|nullable|string|max:1000']);
        // AdBannerSettingsUpdate defaults video_url to None, so an
        // omitted field clears the URL rather than leaving it alone --
        // preserved here rather than "improved" into a partial update.
        $newUrl = $data['video_url'] ?? null;

        $settings = $this->getOrCreateSettings($slot);
        $oldUrl = $settings->video_url;
        $oldStoredFilename = $settings->video_stored_filename;
        $oldOriginalFilename = $settings->video_original_filename;

        DB::transaction(function () use ($settings, $slot, $newUrl, $oldUrl, $oldOriginalFilename, $user) {
            $settings->video_url = $newUrl;
            $settings->video_stored_filename = null;
            $settings->video_original_filename = null;
            $settings->video_content_type = null;
            $settings->video_file_size_bytes = null;
            $settings->updated_at = Carbon::now();
            $settings->save();

            Audit::record(
                entityType: 'ad_banner_settings',
                entityId: self::SETTINGS_AUDIT_IDS[$slot],
                action: 'updated',
                actorUserId: $user->id,
                details: "slot={$slot}",
                oldValue: ['video_url' => $oldUrl, 'video_original_filename' => $oldOriginalFilename],
                newValue: ['video_url' => $newUrl],
            );
        });

        if ($oldStoredFilename) {
            AdBannerVideo::delete($oldStoredFilename);
        }

        return response()->json($this->presentSettings($settings->refresh()));
    }

    /**
     * Uploads a video file for one slot, replacing whichever of the
     * URL/a previous upload was live for that SAME slot -- see
     * AdBannerSettings's docblock. Stored on disk
     * (App\Services\AdBannerVideo), never inline in the row: a video
     * is much larger than the logo/photo fields that ARE stored that
     * way.
     */
    public function uploadVideo(Request $request, string $slot)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        // No MIME-type restriction, matching DocumentController's own
        // upload validation ('file' => 'required|file') -- content
        // sniffing is unreliable and easy to spoof anyway; the file
        // input's `accept` attribute steers the browser's own picker,
        // and the wrong content just fails to play, exactly as a wrong
        // .mp4/.webm URL already silently does.
        $request->validate([
            'video' => 'required|file|max:'.self::MAX_VIDEO_KB,
        ]);
        $file = $request->file('video');

        $settings = $this->getOrCreateSettings($slot);
        $oldUrl = $settings->video_url;
        $oldStoredFilename = $settings->video_stored_filename;
        $oldOriginalFilename = $settings->video_original_filename;

        $originalName = (string) $file->getClientOriginalName();
        $storedFilename = AdBannerVideo::save($originalName, (string) file_get_contents($file->getRealPath()));

        // Derived from the extension, deliberately not trusted from
        // either getMimeType() (content-sniffed -- meant for security
        // decisions, and misfires on a real upload whose bytes don't
        // happen to read as video: came back `text/plain`) or
        // getClientMimeType() (browser-reported, but inconsistent --
        // even confirmed against Laravel's own upload-faking test
        // helper, which calls a .mp4 `application/mp4`). Either wrong
        // value silently breaks playback: <video> requires a `video/*`
        // Content-Type to even attempt decoding a response. Only two
        // extensions are supported at all (see the admin form), so a
        // small fixed map is exact rather than a guess.
        $contentType = strtolower((string) $file->getClientOriginalExtension()) === 'webm' ? 'video/webm' : 'video/mp4';

        DB::transaction(function () use ($settings, $slot, $storedFilename, $originalName, $contentType, $file, $oldUrl, $oldOriginalFilename, $user) {
            $settings->video_url = null;
            $settings->video_stored_filename = $storedFilename;
            $settings->video_original_filename = $originalName;
            $settings->video_content_type = $contentType;
            $settings->video_file_size_bytes = $file->getSize();
            $settings->updated_at = Carbon::now();
            $settings->save();

            Audit::record(
                entityType: 'ad_banner_settings',
                entityId: self::SETTINGS_AUDIT_IDS[$slot],
                action: 'video_uploaded',
                actorUserId: $user->id,
                details: "slot={$slot}",
                oldValue: ['video_url' => $oldUrl, 'video_original_filename' => $oldOriginalFilename],
                newValue: ['video_original_filename' => $originalName, 'video_file_size_bytes' => $file->getSize()],
            );
        });

        if ($oldStoredFilename) {
            AdBannerVideo::delete($oldStoredFilename);
        }

        return response()->json($this->presentSettings($settings->refresh()));
    }

    /**
     * Unauthenticated, same as publicAdBanner() -- the Login page
     * needs to play this before anyone has signed in. Not slotted in
     * the URL itself: $filename is a server-generated UUID
     * (AdBannerVideo::save()) already unique across both slots, so
     * this just looks up whichever row currently owns it -- a stale
     * or guessed name matches no row and 404s rather than serving
     * whatever happens to be on disk. Streamed via response()->file(),
     * which (through Symfony's BinaryFileResponse) handles Range
     * requests automatically -- browsers rely on that for a <video>
     * element even without the user seeking.
     */
    public function serveVideo(string $filename)
    {
        $settings = AdBannerSettings::where('video_stored_filename', $filename)->first();
        if (! $settings) {
            throw new ApiException(404, 'No such video.');
        }

        $path = AdBannerVideo::path($settings->video_stored_filename);
        if ($path === null) {
            throw new ApiException(404, 'Video file missing on disk.');
        }

        return response()->file($path, ['Content-Type' => $settings->video_content_type ?: 'video/mp4']);
    }

    private function effectiveVideoUrl(AdBannerSettings $settings): ?string
    {
        if ($settings->hasUploadedVideo()) {
            return "/api/announcements/video/{$settings->video_stored_filename}";
        }

        return $settings->video_url;
    }

    private function presentSettings(AdBannerSettings $settings): array
    {
        return [
            'video_url' => $this->effectiveVideoUrl($settings),
            'video_source' => $settings->hasUploadedVideo() ? 'upload' : ($settings->video_url ? 'url' : 'none'),
            'video_original_filename' => $settings->video_original_filename,
            'video_file_size_bytes' => $settings->video_file_size_bytes,
        ];
    }

    /**
     * Every announcement, including inactive ones -- for the admin
     * management screen. See publicAdBanner() for the filtered,
     * unauthenticated view everyone else sees.
     */
    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $rows = Announcement::orderBy('sort_order')->orderBy('created_at')->get();

        return response()->json($rows->map(fn (Announcement $a) => $this->present($a))->all());
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $data = $request->validate([
            'tag' => 'sometimes|nullable|string|max:30',
            'text' => 'required|string|min:1|max:500',
            'sort_order' => 'sometimes|integer',
        ]);

        $announcement = DB::transaction(function () use ($data, $user) {
            $announcement = Announcement::create([
                'tag' => $data['tag'] ?? null,
                'text' => $data['text'],
                'sort_order' => $data['sort_order'] ?? 0,
            ]);

            Audit::record(
                entityType: 'announcement',
                entityId: $announcement->id,
                action: 'created',
                actorUserId: $user->id,
                details: "text={$data['text']}",
            );

            return $announcement;
        });

        return response()->json($this->present($announcement->refresh()));
    }

    public function update(Request $request, string $announcementId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $request->validate([
            'tag' => 'sometimes|nullable|string|max:30',
            'text' => 'sometimes|nullable|string|min:1|max:500',
            'sort_order' => 'sometimes|nullable|integer',
            'is_active' => 'sometimes|nullable|boolean',
        ]);

        $announcement = $this->announcementOr404($announcementId);

        // Python uses model_dump(exclude_unset=True): only the fields
        // actually present in the request body are applied, and the
        // audit diff records only those that really changed.
        $fields = $request->only(['tag', 'text', 'sort_order', 'is_active']);
        $oldValue = [];
        $newValue = [];
        foreach ($fields as $field => $new) {
            $old = $announcement->{$field};
            if ($old !== $new) {
                $oldValue[$field] = $old;
                $newValue[$field] = $new;
            }
            $announcement->{$field} = $new;
        }

        DB::transaction(function () use ($announcement, $user, $oldValue, $newValue) {
            $announcement->save();
            Audit::record(
                entityType: 'announcement',
                entityId: $announcement->id,
                action: 'updated',
                actorUserId: $user->id,
                oldValue: $oldValue ?: null,
                newValue: $newValue ?: null,
            );
        });

        return response()->json($this->present($announcement->refresh()));
    }

    /**
     * A genuine delete, not a soft-delete -- unlike the business/
     * financial records CLAUDE.md's "never permanently delete" rule
     * covers, an announcement is a marketing blurb with no downstream
     * references, so removing a mistaken one outright is reasonable.
     * Toggle `is_active` instead to hide one without losing it.
     * (Carried across verbatim from the Python router, which records
     * this decision at its own delete handler.)
     */
    public function destroy(Request $request, string $announcementId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $announcement = $this->announcementOr404($announcementId);

        DB::transaction(function () use ($announcement, $user) {
            // The audit entry is written BEFORE the delete, same order
            // as Python, so the trail keeps the text that was removed.
            Audit::record(
                entityType: 'announcement',
                entityId: $announcement->id,
                action: 'deleted',
                actorUserId: $user->id,
                details: "text={$announcement->text}",
            );
            $announcement->delete();
        });

        return response()->noContent();
    }
}
