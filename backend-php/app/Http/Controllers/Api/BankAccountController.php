<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\BankAccount;
use App\Models\BankTransaction;
use App\Services\Audit;
use App\Services\Authority;
use App\Support\Money;
use Illuminate\Http\Request;

/**
 * Bank Master File. Mirrors backend/app/routers/bank_accounts.py.
 *
 * NOT yet converted: CSV/Excel export.
 */
class BankAccountController extends Controller
{
    private const MODULE = 'finance_accounting';

    private function present(BankAccount $bank): array
    {
        $movement = BankTransaction::where('bank_account_id', $bank->id)->where('is_voided', false)->get()
            ->reduce(fn (Money $carry, BankTransaction $t) => $carry->plus(Money::of($t->debit_sgd))->minus(Money::of($t->credit_sgd)), Money::of(0));
        $balance = Money::of($bank->opening_balance_sgd)->plus($movement);

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

        $query = BankAccount::where('company_id', $user->company_id);
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return $query->orderBy('bank_name')->get()->map(fn (BankAccount $b) => $this->present($b))->values();
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
