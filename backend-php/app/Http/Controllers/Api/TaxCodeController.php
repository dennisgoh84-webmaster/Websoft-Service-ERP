<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\GroupModuleAuthority;
use App\Models\TaxCode;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Exports;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Tax Type maintenance -- CRUD over the TaxCode table used for GST.
 * Mirrors backend/app/routers/tax_codes.py 1:1.
 *
 * Rates are data, not hard-coded, so a change (Singapore has moved its
 * GST rate twice in recent years) is an edit here rather than a code
 * change; historical invoices keep the rate they were actually raised
 * at regardless of later edits (Invoice.gst_rate).
 *
 * The `tax_codes` table itself already existed -- it was created with
 * Billing/Invoicing, which reads it. This adds the maintenance screen's
 * endpoints over it, which had no PHP equivalent.
 */
class TaxCodeController extends Controller
{
    private const MODULE = 'finance_accounting';

    /** @var array<int, string> */
    private const EXPORT_FIELDS = ['code', 'name', 'kind', 'rate_percent', 'form5_box', 'is_active'];

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        return response()->json(
            $this->filtered($user->company_id, $request)->map(fn (TaxCode $t) => $this->out($t))
        );
    }

    public function exportCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        $csv = Exports::rowsToCsv(self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request));

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=tax-types.csv',
        ]);
    }

    public function exportExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        $data = Exports::rowsToExcel(
            self::EXPORT_FIELDS,
            $this->exportRows($user->company_id, $request),
            'Tax Types'
        );

        return response($data, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename=tax-types.xlsx',
        ]);
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);

        $data = $request->validate([
            // Mirrors Python's TaxCodeCreate exactly: is_active is NOT
            // settable on create (the column default applies), and
            // rate_percent is bounded 0..100 (Field(ge=0, le=100)).
            'code' => 'required|string|min:1|max:10',
            'name' => 'required|string|min:1|max:100',
            'rate_percent' => 'required|numeric|min:0|max:100',
            'kind' => 'sometimes|in:'.implode(',', TaxCode::KINDS),
            'form5_box' => 'sometimes|nullable|string',
        ]);
        $this->assertBoxFitsKind($data['form5_box'] ?? null, $data['kind'] ?? TaxCode::KIND_SUPPLY);

        $exists = TaxCode::where('company_id', $user->company_id)->where('code', $data['code'])->first();
        if ($exists) {
            throw new ApiException(409, "Tax code {$data['code']} already exists.");
        }

        $taxCode = DB::transaction(function () use ($data, $user) {
            $taxCode = TaxCode::create($data + ['company_id' => $user->company_id]);

            Audit::record(
                entityType: 'tax_code',
                entityId: $taxCode->id,
                action: 'created',
                actorUserId: $user->id,
                details: "{$taxCode->code} {$taxCode->name} @ {$taxCode->rate_percent}%",
                newValue: [
                    'code' => $taxCode->code,
                    'name' => $taxCode->name,
                    'rate_percent' => (string) $taxCode->rate_percent,
                ],
            );

            return $taxCode;
        });

        return response()->json($this->out($taxCode->refresh()));
    }

    public function update(Request $request, string $taxCodeId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);

        $data = $request->validate([
            'code' => 'sometimes|string|max:10',
            'name' => 'sometimes|string|max:100',
            'rate_percent' => 'sometimes|numeric|min:0|max:100',
            'is_active' => 'sometimes|boolean',
            'kind' => 'sometimes|in:'.implode(',', TaxCode::KINDS),
            'form5_box' => 'sometimes|nullable|string',
        ]);

        $taxCode = TaxCode::where('company_id', $user->company_id)->find($taxCodeId);
        if (! $taxCode) {
            throw new ApiException(404, 'Tax code not found');
        }

        if (array_key_exists('form5_box', $data) || array_key_exists('kind', $data)) {
            $this->assertBoxFitsKind(
                array_key_exists('form5_box', $data) ? $data['form5_box'] : $taxCode->form5_box,
                $data['kind'] ?? $taxCode->kind,
            );
        }

        DB::transaction(function () use ($data, $taxCode, $user) {
            // Python compares old != new per field and records only what
            // actually changed, stringifying both sides.
            $oldValue = [];
            $newValue = [];
            foreach (['code', 'name', 'rate_percent', 'is_active', 'kind', 'form5_box'] as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $old = $taxCode->$field;
                $new = $data[$field];
                if ($old == $new) {
                    continue;
                }
                $oldValue[$field] = (string) $old;
                $newValue[$field] = (string) $new;
                $taxCode->$field = $new;
            }
            $taxCode->save();

            Audit::record(
                entityType: 'tax_code',
                entityId: $taxCode->id,
                action: 'updated',
                actorUserId: $user->id,
                details: "{$taxCode->code} {$taxCode->name}",
                oldValue: $oldValue ?: null,
                newValue: $newValue ?: null,
            );
        });

        return response()->json($this->out($taxCode->refresh()));
    }

    /** @return Collection<int, TaxCode> */
    private function filtered(string $companyId, Request $request)
    {
        $query = TaxCode::where('company_id', $companyId);
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        if (in_array($request->query('kind'), TaxCode::KINDS, true)) {
            $query->where('kind', $request->query('kind'));
        }

        return $query->orderBy('code')->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(string $companyId, Request $request): array
    {
        return $this->filtered($companyId, $request)->map(fn (TaxCode $t) => [
            'code' => $t->code,
            'name' => $t->name,
            'kind' => $t->kind,
            'rate_percent' => (string) $t->rate_percent,
            'form5_box' => $t->form5_box ?? '',
            'is_active' => $t->is_active,
        ])->all();
    }

    /** @return array<string, mixed> */
    private function out(TaxCode $t): array
    {
        return [
            'id' => $t->id,
            'code' => $t->code,
            'name' => $t->name,
            // Python's TaxCodeOut types rate_percent as float, so the
            // wire format is a bare number, not a numeric string.
            'rate_percent' => (float) $t->rate_percent,
            'is_active' => $t->is_active,
            'kind' => $t->kind,
            'form5_box' => $t->form5_box,
        ];
    }

    /** A supply code goes in a supply box, a purchase code in a purchase box (decision 47.4). */
    private function assertBoxFitsKind(?string $box, string $kind): void
    {
        if ($box !== null && $box !== '' && ! in_array($box, TaxCode::FORM5_BOXES[$kind] ?? [], true)) {
            throw new ApiException(422, sprintf('A %s code counts in one of: %s.', $kind, implode(', ', TaxCode::FORM5_BOXES[$kind])));
        }
    }
}
