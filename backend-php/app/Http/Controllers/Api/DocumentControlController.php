<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\DocumentNumberFormat;
use App\Models\DocumentSequence;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Numbering;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Document Control -- view and (carefully) adjust the document
 * numbering counters behind App\Services\Numbering, and customize each
 * document kind's number format (prefix / digit padding / whether the
 * year is included -- confirmed 2026-09-11). Mirrors
 * backend/app/routers/document_control.py 1:1.
 *
 * Editing `last_number` is the one genuinely dangerous action here:
 * set it too low and the next document raised collides with one
 * already issued. Changing a format is lower-risk (it can't collide
 * with a number already issued, since the counter itself is
 * untouched) but is still restricted to FULL access and requires a
 * reason, both recorded to Event Logs, for the same "this is a
 * deliberate, reasoned change" posture as adjusting a counter. A
 * format change only affects numbers issued from that point on --
 * every document already numbered keeps the text it was given
 * (CLAUDE.md: never modify existing business records).
 *
 * Both tables (document_sequences, document_number_formats) and
 * Numbering::format() already existed, built with the Contracts
 * conversion; this controller is the admin screen over them that was
 * missing. Nothing about how numbers are allocated changes here.
 */
class DocumentControlController extends Controller
{
    private const MODULE = 'core_administration';

    /** The built-in default when a doc_kind has no override row. */
    private const DEFAULT_NUMBER_LENGTH = 4;

    private const DEFAULT_INCLUDE_YEAR = true;

    private function defaultPrefix(string $docKind): string
    {
        return Numbering::PREFIXES[$docKind] ?? strtoupper(substr($docKind, 0, 3));
    }

    private function present(DocumentSequence $seq): array
    {
        $fmt = DocumentNumberFormat::where('company_id', $seq->company_id)
            ->where('doc_kind', $seq->doc_kind)
            ->first();

        return [
            'id' => $seq->id,
            'doc_kind' => $seq->doc_kind,
            'prefix' => $fmt ? $fmt->prefix : $this->defaultPrefix($seq->doc_kind),
            'year' => $seq->year,
            'last_number' => $seq->last_number,
            // What the next document raised in this kind/year will look
            // like, given the current format -- computed server-side so
            // the UI never has to guess the padding/layout.
            'next_number' => Numbering::format($seq->company_id, $seq->doc_kind, $seq->year, $seq->last_number + 1),
        ];
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $rows = DocumentSequence::where('company_id', $user->company_id)
            ->orderByDesc('year')
            ->orderBy('doc_kind')
            ->get();

        return response()->json($rows->map(fn (DocumentSequence $r) => $this->present($r))->all());
    }

    public function update(Request $request, string $sequenceId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $data = $request->validate([
            'last_number' => 'required|integer|min:0',
            'reason' => 'required|string|min:1',
        ]);

        $seq = DocumentSequence::find($sequenceId);
        if (! $seq || $seq->company_id !== $user->company_id) {
            throw new ApiException(404, 'Document sequence not found');
        }

        $oldNumber = $seq->last_number;

        DB::transaction(function () use ($seq, $data, $user, $oldNumber) {
            $seq->last_number = $data['last_number'];
            $seq->save();

            Audit::record(
                entityType: 'document_sequence',
                entityId: $seq->id,
                action: 'last_number_changed',
                actorUserId: $user->id,
                reason: $data['reason'],
                details: sprintf(
                    '%s-%d: last_number %d -> %d',
                    $this->defaultPrefix($seq->doc_kind),
                    $seq->year,
                    $oldNumber,
                    $data['last_number'],
                ),
                oldValue: ['last_number' => $oldNumber],
                newValue: ['last_number' => $data['last_number']],
            );
        });

        return response()->json($this->present($seq->refresh()));
    }

    /**
     * Every doc_kind this company has ever numbered, plus every kind
     * built into the app (Numbering::PREFIXES) -- so a kind not yet
     * used this year (or ever, for a brand-new company) still shows up
     * to customize ahead of time, not only after its first document is
     * issued.
     *
     * @return list<string>
     */
    private function knownDocKinds(string $companyId): array
    {
        $used = DocumentSequence::where('company_id', $companyId)->distinct()->pluck('doc_kind')->all();
        $kinds = array_values(array_unique(array_merge($used, array_keys(Numbering::PREFIXES))));
        sort($kinds);

        return $kinds;
    }

    private function presentFormat(string $companyId, string $docKind, ?DocumentNumberFormat $fmt, int $year): array
    {
        return [
            'doc_kind' => $docKind,
            'prefix' => $fmt ? $fmt->prefix : $this->defaultPrefix($docKind),
            'number_length' => $fmt ? $fmt->number_length : self::DEFAULT_NUMBER_LENGTH,
            'include_year' => $fmt ? $fmt->include_year : self::DEFAULT_INCLUDE_YEAR,
            // False when this doc_kind has no override row yet and is
            // showing the built-in default -- lets the UI say "default"
            // vs "custom".
            'is_custom' => $fmt !== null,
            'example' => Numbering::format($companyId, $docKind, $year, 1),
        ];
    }

    public function listFormats(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $overrides = DocumentNumberFormat::where('company_id', $user->company_id)->get()->keyBy('doc_kind');
        $year = Carbon::today()->year;

        $out = [];
        foreach ($this->knownDocKinds($user->company_id) as $docKind) {
            $out[] = $this->presentFormat($user->company_id, $docKind, $overrides->get($docKind), $year);
        }

        return response()->json($out);
    }

    public function setFormat(Request $request, string $docKind)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        // Confirmed 2026-09-11: "customization of the running number
        // formatting and front alphabet" -- uppercase letters/digits
        // only (the "-year-seq" separators are added automatically, not
        // part of what's customizable here). Same rules as
        // DocumentNumberFormatUpdate's Pydantic constraints.
        $data = $request->validate([
            'prefix' => ['required', 'string', 'min:1', 'max:10', 'regex:/^[A-Z0-9]+$/'],
            'number_length' => 'required|integer|min:1|max:10',
            'include_year' => 'sometimes|boolean',
            'reason' => 'required|string|min:1',
        ]);
        $includeYear = $data['include_year'] ?? true;

        $fmt = DocumentNumberFormat::where('company_id', $user->company_id)
            ->where('doc_kind', $docKind)
            ->first();

        $oldValue = $fmt !== null
            ? ['prefix' => $fmt->prefix, 'number_length' => $fmt->number_length, 'include_year' => $fmt->include_year]
            : [
                'prefix' => $this->defaultPrefix($docKind),
                'number_length' => self::DEFAULT_NUMBER_LENGTH,
                'include_year' => self::DEFAULT_INCLUDE_YEAR,
            ];

        $fmt = DB::transaction(function () use ($fmt, $user, $docKind, $data, $includeYear, $oldValue) {
            if ($fmt === null) {
                $fmt = new DocumentNumberFormat(['company_id' => $user->company_id, 'doc_kind' => $docKind]);
            }
            $fmt->prefix = $data['prefix'];
            $fmt->number_length = $data['number_length'];
            $fmt->include_year = $includeYear;
            $fmt->save();

            $newValue = [
                'prefix' => $fmt->prefix,
                'number_length' => $fmt->number_length,
                'include_year' => $fmt->include_year,
            ];
            Audit::record(
                entityType: 'document_number_format',
                entityId: $fmt->id,
                action: 'updated',
                actorUserId: $user->id,
                reason: $data['reason'],
                details: sprintf(
                    '%s: %s -> %s (applies to numbers issued from now on)',
                    $docKind,
                    json_encode($oldValue),
                    json_encode($newValue),
                ),
                oldValue: $oldValue,
                newValue: $newValue,
            );

            return $fmt;
        });

        $fmt->refresh();

        return response()->json(
            $this->presentFormat($user->company_id, $docKind, $fmt, Carbon::today()->year)
        );
    }
}
