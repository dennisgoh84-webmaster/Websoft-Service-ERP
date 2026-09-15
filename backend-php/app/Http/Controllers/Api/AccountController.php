<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Account;
use App\Services\Audit;
use App\Services\Authority;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Chart of Accounts maintenance. Mirrors
 * backend/app/routers/accounts.py. The seeded chart
 * (DatabaseSeeder::CHART_OF_ACCOUNTS) is a conventional Singapore SME
 * starting point (confirmed approach, 2026-09-10), not a decided
 * chart -- this API is how it gets adjusted to how Webmaster actually
 * wants its books structured.
 */
class AccountController extends Controller
{
    use SendsExports;

    /** @var array<int, string> */
    private const EXPORT_FIELDS = ['code', 'name', 'account_type', 'description', 'is_active'];

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

        return $this->filtered($user->company_id, $request)->map(fn (Account $a) => $this->present($a))->values();
    }

    /**
     * The list the screen shows, honouring its filters -- shared with
     * the exports so an Export button always returns what is on
     * screen.
     *
     * @return Collection<int, Account>
     */
    private function filtered(string $companyId, Request $request)
    {
        $query = Account::where('company_id', $companyId);
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }
        if ($request->filled('account_type')) {
            $query->where('account_type', $request->query('account_type'));
        }

        return $query->orderBy('code')->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(string $companyId, Request $request): array
    {
        return $this->filtered($companyId, $request)->map(fn (Account $a) => [
            'code' => $a->code,
            'name' => $a->name,
            'account_type' => $a->account_type,
            'description' => $a->description ?? '',
            'is_active' => $a->is_active,
        ])->all();
    }

    public function exportCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->csvResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request), 'chart-of-accounts.csv'
        );
    }

    public function exportExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->xlsxResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request),
            'Chart of Accounts', 'chart-of-accounts.xlsx'
        );
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
