<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\AdBannerSettings;
use App\Models\Announcement;
use App\Services\Audit;
use App\Services\Authority;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Platform announcements + the promo video URL shown on the ad banner
 * (Login page and every page after signing in -- see
 * frontend/src/components/PromoVideoPanel.tsx). Mirrors
 * backend/app/routers/announcements.py 1:1. Global, not company-
 * scoped -- see App\Models\Announcement's docblock.
 *
 * GET /public is the one unauthenticated endpoint here (mirrors
 * Company Setup's GET /api/companies/public-branding): the Login page
 * needs this before anyone has signed in, and the banner shown after
 * signing in reads the exact same endpoint rather than a second,
 * authenticated copy, since the content is identical for everyone and
 * non-sensitive.
 *
 * Confirmed 2026-09-12: Save = live immediately -- there is no
 * separate draft/publish step, same as every other admin screen in
 * this system (Company Setup, Module Control, Tax Types, ...).
 *
 * Note (docs/planned-work.md #8a): the future, separate "Server
 * Company Central Command" application is planned to push
 * advertisements by writing straight into the `announcements` table of
 * each client database. Nothing is built for that here -- this is only
 * the receiving end, and it already works the moment a row appears,
 * because GET /public reads the table directly with no cache.
 */
class AnnouncementController extends Controller
{
    private const MODULE = 'core_administration';

    /** Singleton row id for AdBannerSettings (see that model's docblock). */
    private const SETTINGS_ID = 1;

    /**
     * A fixed, arbitrary UUID standing in for the settings row in the
     * audit trail, since AuditLogEntry::$entity_id is a UUID but this
     * settings row isn't. Python builds the same value with
     * uuid.UUID(int=1).
     */
    private const SETTINGS_AUDIT_ID = '00000000-0000-0000-0000-000000000001';

    private function getOrCreateSettings(): AdBannerSettings
    {
        $settings = AdBannerSettings::find(self::SETTINGS_ID);
        if (! $settings) {
            $settings = AdBannerSettings::create(['id' => self::SETTINGS_ID, 'video_url' => null]);
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

    /** Unauthenticated: the video URL plus only the ACTIVE announcements, already ordered. */
    public function publicAdBanner()
    {
        $settings = $this->getOrCreateSettings();
        $items = Announcement::where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'video_url' => $settings->video_url,
            'items' => $items->map(fn (Announcement $a) => $this->present($a))->all(),
        ]);
    }

    public function getSettings(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        return response()->json(['video_url' => $this->getOrCreateSettings()->video_url]);
    }

    public function updateSettings(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $data = $request->validate(['video_url' => 'sometimes|nullable|string|max:1000']);
        // AdBannerSettingsUpdate defaults video_url to None, so an
        // omitted field clears the URL rather than leaving it alone --
        // preserved here rather than "improved" into a partial update.
        $newUrl = $data['video_url'] ?? null;

        $settings = $this->getOrCreateSettings();
        $oldUrl = $settings->video_url;

        DB::transaction(function () use ($settings, $newUrl, $oldUrl, $user) {
            $settings->video_url = $newUrl;
            $settings->updated_at = Carbon::now();
            $settings->save();

            Audit::record(
                entityType: 'ad_banner_settings',
                entityId: self::SETTINGS_AUDIT_ID,
                action: 'updated',
                actorUserId: $user->id,
                oldValue: ['video_url' => $oldUrl],
                newValue: ['video_url' => $newUrl],
            );
        });

        return response()->json(['video_url' => $settings->video_url]);
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
