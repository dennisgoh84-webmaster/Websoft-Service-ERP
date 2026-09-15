<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Account;
use App\Models\GroupModuleAuthority;
use App\Models\ReferenceCode;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Exports;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Reference Monitor maintenance -- GL sub-codes under one Chart of
 * Accounts row. Mirrors backend/app/routers/reference_codes.py 1:1.
 *
 * Lives under the same finance_accounting module authority as Chart of
 * Accounts, since it is a direct extension of it.
 *
 * GET /api/reference-codes was the single most common 404 left in
 * smoke tests against backend-php before this conversion -- the
 * Reference Monitor screen called it on every visit.
 */
class ReferenceCodeController extends Controller
{
    private const MODULE = 'finance_accounting';

    /** @var array<int, string> */
    private const EXPORT_FIELDS = ['code', 'name', 'account_code', 'account_name', 'is_active'];

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        return response()->json(
            $this->filtered($user->company_id, $request)->map(fn (ReferenceCode $r) => $this->out($r))
        );
    }

    public function exportCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        $csv = Exports::rowsToCsv(self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request));

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename=reference-codes.csv',
        ]);
    }

    public function exportExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        $data = Exports::rowsToExcel(
            self::EXPORT_FIELDS,
            $this->exportRows($user->company_id, $request),
            'Reference Codes'
        );

        return response($data, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename=reference-codes.xlsx',
        ]);
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);

        $data = $request->validate([
            'account_id' => 'required|uuid',
            'code' => 'required|string|min:1|max:50',
            'name' => 'required|string|min:1',
        ]);

        // A reference code may only hang off an account of the caller's
        // own company -- checked before the duplicate check, as Python
        // does, so a cross-company account_id is a 404 rather than a 409.
        $account = Account::where('company_id', $user->company_id)->find($data['account_id']);
        if (! $account) {
            throw new ApiException(404, 'Account not found');
        }

        $exists = ReferenceCode::where('company_id', $user->company_id)->where('code', $data['code'])->first();
        if ($exists) {
            throw new ApiException(409, "Reference code {$data['code']} already exists.");
        }

        $rc = DB::transaction(function () use ($data, $user, $account) {
            $rc = ReferenceCode::create([
                'company_id' => $user->company_id,
                'account_id' => $data['account_id'],
                'code' => $data['code'],
                'name' => $data['name'],
            ]);

            Audit::record(
                entityType: 'reference_code',
                entityId: $rc->id,
                action: 'created',
                actorUserId: $user->id,
                details: "{$data['code']} {$data['name']} -> {$account->code}",
                newValue: [
                    'code' => $data['code'],
                    'name' => $data['name'],
                    'account_code' => $account->code,
                ],
            );

            return $rc;
        });

        return response()->json($this->out($rc->fresh('account')));
    }

    public function update(Request $request, string $referenceCodeId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);

        $data = $request->validate([
            'account_id' => 'sometimes|uuid',
            'code' => 'sometimes|string|min:1|max:50',
            'name' => 'sometimes|string|min:1',
            'is_active' => 'sometimes|boolean',
        ]);

        $rc = ReferenceCode::where('company_id', $user->company_id)->find($referenceCodeId);
        if (! $rc) {
            throw new ApiException(404, 'Reference code not found');
        }

        // Re-pointing at another company's account is a 404, same as create.
        if (array_key_exists('account_id', $data) && $data['account_id'] !== null) {
            $account = Account::where('company_id', $user->company_id)->find($data['account_id']);
            if (! $account) {
                throw new ApiException(404, 'Account not found');
            }
        }

        DB::transaction(function () use ($data, $rc, $user) {
            $oldValue = [];
            $newValue = [];
            foreach (['account_id', 'code', 'name', 'is_active'] as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $old = $rc->$field;
                $new = $data[$field];
                if ($old == $new) {
                    continue;
                }
                $oldValue[$field] = (string) $old;
                $newValue[$field] = (string) $new;
                $rc->$field = $new;
            }
            $rc->save();

            Audit::record(
                entityType: 'reference_code',
                entityId: $rc->id,
                action: 'updated',
                actorUserId: $user->id,
                details: "{$rc->code} {$rc->name}",
                oldValue: $oldValue ?: null,
                newValue: $newValue ?: null,
            );
        });

        return response()->json($this->out($rc->fresh('account')));
    }

    /** @return Collection<int, ReferenceCode> */
    private function filtered(string $companyId, Request $request)
    {
        $query = ReferenceCode::with('account')->where('company_id', $companyId);
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }
        if ($accountId = $request->query('account_id')) {
            $query->where('account_id', $accountId);
        }

        return $query->orderBy('code')->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(string $companyId, Request $request): array
    {
        return $this->filtered($companyId, $request)->map(fn (ReferenceCode $r) => [
            'code' => $r->code,
            'name' => $r->name,
            // Python exports a missing account as an empty string, but
            // types it null in the JSON schema -- kept distinct here too.
            'account_code' => $r->account?->code ?? '',
            'account_name' => $r->account?->name ?? '',
            'is_active' => $r->is_active,
        ])->all();
    }

    /** @return array<string, mixed> */
    private function out(ReferenceCode $r): array
    {
        return [
            'id' => $r->id,
            'account_id' => $r->account_id,
            'account_code' => $r->account?->code,
            'account_name' => $r->account?->name,
            'code' => $r->code,
            'name' => $r->name,
            'is_active' => $r->is_active,
            'created_at' => $r->created_at?->toJSON(),
        ];
    }
}
