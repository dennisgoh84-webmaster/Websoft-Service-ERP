<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Account;
use App\Models\BankAccount;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\BankBook;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Bank Master File. Mirrors backend/app/routers/bank_accounts.py.
 */
class BankAccountController extends Controller
{
    use SendsExports;

    /** @var array<int, string> */
    private const EXPORT_FIELDS = [
        'bank_name', 'account_name', 'account_number', 'branch', 'swift_code',
        'currency_code', 'gl_account_code', 'is_active',
    ];

    private const MODULE = 'finance_accounting';

    private function present(BankAccount $bank): array
    {
        // Shared with the Bank Book ledger's running balance, so the
        // account list and the ledger can never report different
        // numbers for the same account.
        $balance = BankBook::currentBalance($bank);

        return [
            'id' => $bank->id,
            'bank_name' => $bank->bank_name,
            'account_name' => $bank->account_name,
            'account_number' => $bank->account_number,
            'branch' => $bank->branch,
            'swift_code' => $bank->swift_code,
            'currency_code' => $bank->currency_code,
            'gl_account_id' => $bank->gl_account_id,
            'opening_balance_sgd' => (float) $bank->opening_balance_sgd,
            'opening_balance_date' => optional($bank->opening_balance_date)->toDateString(),
            'current_balance_sgd' => $balance->toFloat(),
            'is_active' => $bank->is_active,
        ];
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->filtered($user->company_id, $request)
            ->map(fn (BankAccount $b) => $this->present($b))->values();
    }

    /**
     * The list the screen shows, honouring its filter -- shared with
     * the exports so an Export button always returns what is on
     * screen.
     *
     * @return Collection<int, BankAccount>
     */
    private function filtered(string $companyId, Request $request)
    {
        $query = BankAccount::where('company_id', $companyId);
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return $query->orderBy('bank_name')->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(string $companyId, Request $request): array
    {
        $glCodes = Account::where('company_id', $companyId)->pluck('code', 'id');

        return $this->filtered($companyId, $request)->map(fn (BankAccount $b) => [
            'bank_name' => $b->bank_name,
            'account_name' => $b->account_name,
            'account_number' => $b->account_number,
            'branch' => $b->branch ?? '',
            'swift_code' => $b->swift_code ?? '',
            'currency_code' => $b->currency_code,
            'gl_account_code' => $glCodes[$b->gl_account_id] ?? '',
            'is_active' => $b->is_active,
        ])->all();
    }

    public function exportCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->csvResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request), 'bank-accounts.csv'
        );
    }

    public function exportExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->xlsxResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request),
            'Bank Accounts', 'bank-accounts.xlsx'
        );
    }

    public function show(Request $request, string $bankAccountId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $bank = BankAccount::find($bankAccountId);
        if (! $bank || $bank->company_id !== $user->company_id) {
            throw new ApiException(404, 'Bank account not found');
        }

        return response()->json($this->present($bank));
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $request->validate([
            'bank_name' => 'required|string|min:1|max:150',
            'account_name' => 'required|string|min:1|max:150',
            'account_number' => 'required|string|min:1|max:50',
            'branch' => 'sometimes|nullable|string',
            'swift_code' => 'sometimes|nullable|string',
            'currency_code' => 'sometimes|string|min:3|max:3',
            'gl_account_id' => 'sometimes|nullable|uuid',
            'opening_balance_sgd' => 'sometimes|numeric',
            'opening_balance_date' => 'sometimes|nullable|date',
        ]);

        $bank = BankAccount::create(array_merge(['company_id' => $user->company_id], $data));

        Audit::record(
            entityType: 'bank_account',
            entityId: $bank->id,
            action: 'created',
            actorUserId: $user->id,
            details: "{$bank->bank_name} {$bank->account_name}",
            newValue: ['bank_name' => $bank->bank_name, 'account_name' => $bank->account_name],
        );

        return response()->json($this->present($bank->fresh()));
    }

    public function update(Request $request, string $bankAccountId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $bank = BankAccount::find($bankAccountId);
        if (! $bank || $bank->company_id !== $user->company_id) {
            throw new ApiException(404, 'Bank account not found');
        }

        $fields = $request->validate([
            'bank_name' => 'sometimes|string|min:1|max:150',
            'account_name' => 'sometimes|string|min:1|max:150',
            'account_number' => 'sometimes|string|min:1|max:50',
            'branch' => 'sometimes|nullable|string',
            'swift_code' => 'sometimes|nullable|string',
            'currency_code' => 'sometimes|string|min:3|max:3',
            'gl_account_id' => 'sometimes|nullable|uuid',
            'opening_balance_sgd' => 'sometimes|numeric',
            'opening_balance_date' => 'sometimes|nullable|date',
            'is_active' => 'sometimes|boolean',
        ]);

        $bank->fill($fields);
        $bank->save();

        Audit::record(
            entityType: 'bank_account',
            entityId: $bank->id,
            action: 'updated',
            actorUserId: $user->id,
            details: "{$bank->bank_name} {$bank->account_name}",
        );

        return response()->json($this->present($bank->fresh()));
    }
}
