<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Account;
use App\Models\GLType;
use App\Models\GroupModuleAuthority;
use App\Services\Audit;
use App\Services\Authority;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GL Types -- a finer classification within the 5 AccountType classes
 * that an Account can optionally carry. Mirrors
 * backend/app/routers/gl_types.py 1:1.
 */
class GLTypeController extends Controller
{
    private const MODULE = 'finance_accounting';

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        $query = GLType::where('company_id', $user->company_id);
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return response()->json(
            $query->orderBy('account_type')->orderBy('code')->get()->map(fn (GLType $g) => $this->out($g))
        );
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);

        // Mirrors Python's GLTypeCreate: no is_active field, so a GL Type
        // cannot be created pre-deactivated.
        $data = $request->validate([
            'code' => 'required|string|min:1|max:20',
            'name' => 'required|string|min:1|max:100',
            'account_type' => 'required|string|in:'.implode(',', self::ACCOUNT_TYPES),
        ]);

        $exists = GLType::where('company_id', $user->company_id)->where('code', $data['code'])->first();
        if ($exists) {
            throw new ApiException(409, "GL Type code {$data['code']} already exists.");
        }

        $glType = DB::transaction(function () use ($data, $user) {
            $glType = GLType::create($data + ['company_id' => $user->company_id]);

            Audit::record(
                entityType: 'gl_type',
                entityId: $glType->id,
                action: 'created',
                actorUserId: $user->id,
                details: "{$data['code']} {$data['name']}",
                newValue: [
                    'code' => $data['code'],
                    'name' => $data['name'],
                    'account_type' => $data['account_type'],
                ],
            );

            return $glType;
        });

        return response()->json($this->out($glType->refresh()));
    }

    public function update(Request $request, string $glTypeId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);

        $data = $request->validate([
            'code' => 'sometimes|string|min:1|max:20',
            'name' => 'sometimes|string|min:1|max:100',
            'account_type' => 'sometimes|string|in:'.implode(',', self::ACCOUNT_TYPES),
            'is_active' => 'sometimes|boolean',
        ]);

        $glType = GLType::where('company_id', $user->company_id)->find($glTypeId);
        if (! $glType) {
            throw new ApiException(404, 'GL Type not found');
        }

        DB::transaction(function () use ($data, $glType, $user) {
            $oldValue = [];
            $newValue = [];
            foreach (['code', 'name', 'account_type', 'is_active'] as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $old = $glType->$field;
                $new = $data[$field];
                if ($old == $new) {
                    continue;
                }
                $oldValue[$field] = $old;
                $newValue[$field] = $new;
                $glType->$field = $new;
            }
            $glType->save();

            Audit::record(
                entityType: 'gl_type',
                entityId: $glType->id,
                action: 'updated',
                actorUserId: $user->id,
                details: "{$glType->code} {$glType->name}",
                oldValue: $oldValue ?: null,
                newValue: $newValue ?: null,
            );
        });

        return response()->json($this->out($glType->refresh()));
    }

    /** @var array<int, string> */
    private const ACCOUNT_TYPES = [
        Account::TYPE_ASSET,
        Account::TYPE_LIABILITY,
        Account::TYPE_EQUITY,
        Account::TYPE_REVENUE,
        Account::TYPE_EXPENSE,
    ];

    /** @return array<string, mixed> */
    private function out(GLType $g): array
    {
        return [
            'id' => $g->id,
            'code' => $g->code,
            'name' => $g->name,
            'account_type' => $g->account_type,
            'is_active' => $g->is_active,
        ];
    }
}
