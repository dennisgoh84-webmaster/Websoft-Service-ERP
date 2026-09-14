<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Account;
use App\Services\Audit;
use App\Services\Authority;
use Illuminate\Http\Request;

/**
 * Chart of Accounts maintenance. Mirrors
 * backend/app/routers/accounts.py. The seeded chart
 * (DatabaseSeeder::CHART_OF_ACCOUNTS) is a conventional Singapore SME
 * starting point (confirmed approach, 2026-09-10), not a decided
 * chart -- this API is how it gets adjusted to how Webmaster actually
 * wants its books structured.
 *
 * NOT yet converted: CSV/Excel export.
 */
class AccountController extends Controller
{
    private const MODULE = 'finance_accounting';

    private function present(Account $account): array
    {
        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'account_type' => $account->account_type,
            'description' => $account->description,
            'is_active' => $account->is_active,
        ];
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $query = Account::where('company_id', $user->company_id);
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }
        if ($request->filled('account_type')) {
            $query->where('account_type', $request->query('account_type'));
        }

        return $query->orderBy('code')->get()->map(fn (Account $a) => $this->present($a))->values();
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'code' => 'required|string|min:1|max:20',
            'name' => 'required|string|min:1',
            'account_type' => 'required|in:asset,liability,equity,revenue,expense',
            'description' => 'sometimes|nullable|string',
        ]);

        $existing = Account::where('company_id', $user->company_id)->where('code', $data['code'])->first();
        if ($existing) {
            throw new ApiException(409, "Account code {$data['code']} already exists.");
        }

        $account = Account::create([
            'company_id' => $user->company_id,
            'code' => $data['code'],
            'name' => $data['name'],
            'account_type' => $data['account_type'],
            'description' => $data['description'] ?? null,
        ]);

        Audit::record(
            entityType: 'account',
            entityId: $account->id,
            action: 'created',
            actorUserId: $user->id,
            details: "{$data['code']} {$data['name']}",
            newValue: ['code' => $data['code'], 'name' => $data['name'], 'account_type' => $data['account_type']],
        );

        return response()->json($this->present($account->fresh()));
    }

    public function update(Request $request, string $accountId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $account = Account::find($accountId);
        if (! $account || $account->company_id !== $user->company_id) {
            throw new ApiException(404, 'Account not found');
        }

        $fields = $request->validate([
            'code' => 'sometimes|string|min:1|max:20',
            'name' => 'sometimes|string|min:1',
            'account_type' => 'sometimes|in:asset,liability,equity,revenue,expense',
            'description' => 'sometimes|nullable|string',
            'is_active' => 'sometimes|boolean',
        ]);

        $oldValue = [];
        $newValue = [];
        foreach ($fields as $field => $value) {
            if ($account->{$field} === $value) {
                continue;
            }
            $oldValue[$field] = $account->{$field};
            $newValue[$field] = $value;
            $account->{$field} = $value;
        }

        Audit::record(
            entityType: 'account',
            entityId: $account->id,
            action: 'updated',
            actorUserId: $user->id,
            details: "{$account->code} {$account->name}",
            oldValue: $oldValue ?: null,
            newValue: $newValue ?: null,
        );
        $account->save();

        return response()->json($this->present($account->fresh()));
    }
}
