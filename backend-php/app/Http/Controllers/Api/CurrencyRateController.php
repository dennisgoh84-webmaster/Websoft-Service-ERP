<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\CurrencyRate;
use App\Models\GroupModuleAuthority;
use App\Models\SetupListItem;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Currency;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Currency Rate Table -- setup data only. Mirrors
 * backend/app/routers/currency_rates.py 1:1.
 *
 * Nothing in the app converts an amount using these rates: the system
 * is single-currency (SGD) per CLAUDE.md's approved architecture, and
 * multi-currency is still open item 4b.5. This is the maintenance
 * screen for the table, nothing more.
 *
 * TWO PYTHON BEHAVIOURS CARRIED ACROSS DELIBERATELY:
 *
 * 1. The list filters by `currency_code` only -- never by `is_active`,
 *    even though the column exists and PATCH can set it. An inactive
 *    rate still appears in the list. There is no `include_inactive`
 *    parameter here, unlike GL Types and Tax Types.
 *
 * 2. Create does NOT pre-check the (company, currency, date) unique
 *    constraint the way GL Types and Tax Types pre-check theirs, so a
 *    duplicate raises a database integrity error rather than the 409
 *    those modules return. Matched rather than "fixed" -- see
 *    docs/php-conversion-plan.md, where it is recorded as a finding in
 *    `backend/` for Dennis.
 */
class CurrencyRateController extends Controller
{
    private const MODULE = 'finance_accounting';

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::VIEW);

        $query = CurrencyRate::where('company_id', $user->company_id);
        if ($code = $request->query('currency_code')) {
            $query->where('currency_code', strtoupper((string) $code));
        }

        return response()->json(
            $query->orderBy('currency_code')->orderByDesc('effective_date')->get()
                ->map(fn (CurrencyRate $r) => $this->out($r))
        );
    }

    /**
     * The rate a new document starts with (multi-currency, 2026-09-26):
     * the latest active rate on or before the date. Any signed-in user
     * -- every document form asks, not only Finance.
     */
    public function asAt(Request $request)
    {
        $user = Authenticate::user($request);
        $data = $request->validate(['currency_code' => 'required|string|size:3', 'date' => 'required|date']);
        $code = Currency::code($data['currency_code']);

        return response()->json([
            'currency_code' => $code,
            'date' => Carbon::parse($data['date'])->toDateString(),
            'rate' => ($rate = Currency::rateOn($user->company_id, $code, $data['date'])) === null ? null : (float) $rate,
        ]);
    }

    /** The currencies a document can be raised in: SGD, every currency in the rate table, and the Currency setup list. */
    public function currencies(Request $request)
    {
        $user = Authenticate::user($request);
        $codes = collect([Currency::BASE])
            ->merge(CurrencyRate::where('company_id', $user->company_id)->where('is_active', true)->distinct()->pluck('currency_code'))
            ->merge(SetupListItem::where('list_type', SetupListItem::TYPE_CURRENCY)->where('is_active', true)->pluck('code'))
            ->map(fn ($c) => Currency::code((string) $c))
            ->filter(fn ($c) => preg_match('/^[A-Z]{3}$/', $c))
            ->unique()->sort()->values();
        $codes = $codes->reject(fn ($c) => $c === Currency::BASE)->prepend(Currency::BASE)->values();

        return response()->json($codes);
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);

        // Mirrors Python's CurrencyRateCreate: a 3-letter code, a rate
        // strictly greater than zero, a date. No is_active on create.
        $data = $request->validate([
            'currency_code' => 'required|string|min:3|max:3',
            'rate_to_base' => 'required|numeric|gt:0',
            'effective_date' => 'required|date',
        ]);

        $rate = DB::transaction(function () use ($data, $user) {
            $rate = CurrencyRate::create([
                'company_id' => $user->company_id,
                'currency_code' => strtoupper($data['currency_code']),
                'rate_to_base' => $data['rate_to_base'],
                'effective_date' => $data['effective_date'],
            ]);

            Audit::record(
                entityType: 'currency_rate',
                entityId: $rate->id,
                action: 'created',
                actorUserId: $user->id,
                details: "{$rate->currency_code} @ {$rate->rate_to_base} as at {$rate->effective_date->toDateString()}",
                newValue: [
                    'currency_code' => $rate->currency_code,
                    'rate_to_base' => (string) $rate->rate_to_base,
                ],
            );

            return $rate;
        });

        return response()->json($this->out($rate->refresh()));
    }

    public function update(Request $request, string $rateId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::EDIT);

        // Python's CurrencyRateUpdate carries ONLY these two fields --
        // a rate's currency and effective date are not editable, since
        // together with the company they are its identity.
        $data = $request->validate([
            'rate_to_base' => 'sometimes|numeric|gt:0',
            'is_active' => 'sometimes|boolean',
        ]);

        $rate = CurrencyRate::where('company_id', $user->company_id)->find($rateId);
        if (! $rate) {
            throw new ApiException(404, 'Currency rate not found');
        }

        DB::transaction(function () use ($data, $rate, $user) {
            $oldValue = [];
            $newValue = [];
            foreach (['rate_to_base', 'is_active'] as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $old = $rate->$field;
                $new = $data[$field];
                if ($old == $new) {
                    continue;
                }
                $oldValue[$field] = (string) $old;
                $newValue[$field] = (string) $new;
                $rate->$field = $new;
            }
            $rate->save();

            Audit::record(
                entityType: 'currency_rate',
                entityId: $rate->id,
                action: 'updated',
                actorUserId: $user->id,
                details: "{$rate->currency_code} as at {$rate->effective_date->toDateString()}",
                oldValue: $oldValue ?: null,
                newValue: $newValue ?: null,
            );
        });

        return response()->json($this->out($rate->refresh()));
    }

    /** @return array<string, mixed> */
    private function out(CurrencyRate $r): array
    {
        return [
            'id' => $r->id,
            'currency_code' => $r->currency_code,
            // Python's CurrencyRateOut types this as float.
            'rate_to_base' => (float) $r->rate_to_base,
            'effective_date' => $r->effective_date->toDateString(),
            'is_active' => $r->is_active,
        ];
    }
}
