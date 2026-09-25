<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\CompanyIndividual;
use App\Models\Invoice;
use App\Models\Prospect;
use App\Models\ProspectActivity;
use App\Models\Quotation;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Numbering;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Prospect / Leads (Dennis, 2026-09-26). A prospect is one sales
 * opportunity for a Company / Individual; its activities, quotations and
 * the invoices those quotations led to all hang off it. Sales Staff see
 * their own prospects, the owner / Sales Manager / Sales Supervisor all
 * of them (Prospect::scopeVisibleTo).
 */
class ProspectController extends Controller
{
    use SendsExports;

    private const MODULE = 'prospects';

    /** @var array<int, string> */
    private const EXPORT_FIELDS = [
        'prospect_number', 'title', 'customer_name', 'status', 'salesperson_name', 'source',
        'expected_close_date', 'estimated_value_sgd', 'quoted_amount_sgd', 'billed_amount_sgd',
        'paid_amount_sgd', 'outstanding_amount_sgd',
    ];

    private function prospectOrFail(User $user, string $prospectId): Prospect
    {
        $prospect = Prospect::find($prospectId);
        if (! $prospect || ! $prospect->isVisibleTo($user)) {
            throw new ApiException(404, 'Prospect not found');
        }

        return $prospect;
    }

    private function present(Prospect $p): array
    {
        return [
            'id' => $p->id,
            'prospect_number' => $p->prospect_number,
            'title' => $p->title,
            'customer_id' => $p->customer_id,
            'customer_name' => $p->customer?->name,
            'source' => $p->source,
            'status' => $p->status,
            'expected_close_date' => optional($p->expected_close_date)->toDateString(),
            'salesperson_user_id' => $p->salesperson_user_id,
            'salesperson_name' => $p->salesperson?->full_name,
            'notes' => $p->notes,
            'lost_reason' => $p->lost_reason,
            'created_by_name' => $p->createdBy?->full_name,
            'created_at' => optional($p->created_at)->toJSON(),
            'updated_at' => optional($p->updated_at)->toJSON(),
        ] + $p->amounts();
    }

    /** @return Collection<int, Prospect> */
    private function filtered(User $user, Request $request): Collection
    {
        $query = Prospect::visibleTo($user)->with(['customer', 'salesperson', 'createdBy']);
        // status=active: everything still in the pipeline (not Won / Lost).
        if ($request->query('status') === 'active') {
            $query->whereIn('status', Prospect::ACTIVE_STATUSES);
        } elseif ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        foreach (['customer_id', 'salesperson_user_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->query($field));
            }
        }
        if ($request->filled('q')) {
            $term = '%'.$request->query('q').'%';
            $query->where(fn ($q) => $q->where('title', 'ilike', $term)->orWhere('prospect_number', 'ilike', $term));
        }

        return $query->orderByDesc('created_at')->get();
    }

    public function index(Request $request)
    {
        $user = $this->viewer($request);

        return $this->filtered($user, $request)->map(fn (Prospect $p) => $this->present($p))->values();
    }

    public function exportCsv(Request $request)
    {
        $user = $this->viewer($request);

        return $this->csvResponse(self::EXPORT_FIELDS, $this->exportRows($user, $request), 'prospects.csv');
    }

    public function exportExcel(Request $request)
    {
        $user = $this->viewer($request);

        return $this->xlsxResponse(self::EXPORT_FIELDS, $this->exportRows($user, $request), 'Prospects', 'prospects.xlsx');
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(User $user, Request $request): array
    {
        return $this->filtered($user, $request)->map(function (Prospect $p) {
            $row = $this->present($p);

            return array_intersect_key($row, array_flip(self::EXPORT_FIELDS));
        })->all();
    }

    public function show(Request $request, string $prospectId)
    {
        $user = $this->viewer($request);
        $prospect = $this->prospectOrFail($user, $prospectId);

        return response()->json($this->present($prospect) + [
            'activities' => $prospect->activities()->with('createdBy')->orderByDesc('activity_date')->get()
                ->map(fn (ProspectActivity $a) => [
                    'id' => $a->id,
                    'activity_type' => $a->activity_type,
                    'subject' => $a->subject,
                    'description' => $a->description,
                    'activity_date' => optional($a->activity_date)->toJSON(),
                    'status' => $a->status,
                    'created_by_name' => $a->createdBy?->full_name,
                ])->values(),
            'quotations' => $prospect->quotations()->orderByDesc('quotation_date')->orderByDesc('quotation_number')->get()
                ->map(fn (Quotation $q) => [
                    'id' => $q->id,
                    'quotation_number' => $q->quotation_number,
                    'quotation_date' => optional($q->quotation_date)->toDateString(),
                    'status' => $q->status,
                    'total_amount_sgd' => (float) $q->total_amount_sgd,
                    'counts_as_quoted' => in_array($q->status, Prospect::QUOTED_STATUSES, true),
                ])->values(),
            'invoices' => $prospect->invoices()->orderByDesc('issued_at')->orderByDesc('invoice_number')->get()
                ->map(fn (Invoice $i) => [
                    'id' => $i->id,
                    'invoice_number' => $i->invoice_number,
                    'status' => $i->status,
                    'issued_at' => optional($i->issued_at)->toJSON(),
                    'due_date' => optional($i->due_date)->toDateString(),
                    'total_amount_sgd' => (float) $i->total_amount_sgd,
                    'amount_paid_sgd' => (float) $i->amount_paid_sgd,
                    'outstanding_sgd' => $i->outstandingSgd()->toFloat(),
                ])->values(),
        ]);
    }

    public function store(Request $request)
    {
        $user = $this->editor($request);
        $data = $request->validate($this->rules(true));

        $customer = CompanyIndividual::find($data['customer_id']);
        if (! $customer || $customer->company_id !== $user->company_id) {
            throw new ApiException(404, 'Company / Individual not found');
        }
        $salespersonId = $this->salespersonFor($user, $data['salesperson_user_id'] ?? null);
        $this->requireLostReason($data['status'] ?? Prospect::STATUS_NEW, $data['lost_reason'] ?? null);

        $prospect = DB::transaction(function () use ($user, $data, $salespersonId) {
            $prospect = Prospect::create([
                'company_id' => $user->company_id,
                'prospect_number' => Numbering::next($user->company_id, 'prospect'),
                'customer_id' => $data['customer_id'],
                'title' => $data['title'],
                'source' => $data['source'] ?? null,
                'status' => $data['status'] ?? Prospect::STATUS_NEW,
                'estimated_value_sgd' => $data['estimated_value_sgd'] ?? null,
                'expected_close_date' => $data['expected_close_date'] ?? null,
                'salesperson_user_id' => $salespersonId,
                'notes' => $data['notes'] ?? null,
                'lost_reason' => $data['lost_reason'] ?? null,
                'created_by_user_id' => $user->id,
                'last_edited_by_user_id' => $user->id,
            ]);
            Audit::record('prospect', $prospect->id, 'created', $user->id, companyId: $user->company_id,
                details: "{$prospect->prospect_number} {$prospect->title}",
                newValue: ['customer_id' => $prospect->customer_id, 'title' => $prospect->title, 'salesperson_user_id' => $salespersonId]);

            return $prospect;
        });

        return response()->json($this->present($prospect->fresh()));
    }

    public function update(Request $request, string $prospectId)
    {
        $user = $this->editor($request);
        $prospect = $this->prospectOrFail($user, $prospectId);
        if ($request->has('customer_id') && $request->input('customer_id') !== $prospect->customer_id) {
            throw new ApiException(422, "A prospect's Company / Individual cannot be changed -- its quotations and invoices belong to it.");
        }
        $data = $request->validate($this->rules(false));
        if (array_key_exists('salesperson_user_id', $data)) {
            $data['salesperson_user_id'] = $this->salespersonFor($user, $data['salesperson_user_id'], $prospect->salesperson_user_id);
        }
        $status = $data['status'] ?? $prospect->status;
        $this->requireLostReason($status, array_key_exists('lost_reason', $data) ? $data['lost_reason'] : $prospect->lost_reason);

        $old = [];
        $new = [];
        foreach (['title', 'source', 'status', 'estimated_value_sgd', 'expected_close_date', 'salesperson_user_id', 'notes', 'lost_reason'] as $field) {
            if (! array_key_exists($field, $data)) {
                continue;
            }
            $before = $field === 'expected_close_date' ? optional($prospect->expected_close_date)->toDateString() : $prospect->{$field};
            if ((string) $before === (string) $data[$field]) {
                continue;
            }
            $old[$field] = $before;
            $new[$field] = $data[$field];
            $prospect->{$field} = $data[$field];
        }
        if ($new !== []) {
            $prospect->last_edited_by_user_id = $user->id;
            $prospect->updated_at = Carbon::now();
            $prospect->save();
            Audit::record('prospect', $prospect->id, 'updated', $user->id, companyId: $user->company_id, oldValue: $old, newValue: $new);
        }

        return response()->json($this->present($prospect->fresh()));
    }

    private function rules(bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return [
            'customer_id' => [$creating ? 'required' : 'sometimes', 'uuid'],
            'title' => [$req, 'string', 'min:1', 'max:255'],
            'source' => ['sometimes', 'nullable', 'string', 'max:100'],
            'status' => ['sometimes', Rule::in(Prospect::STATUSES)],
            'estimated_value_sgd' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'expected_close_date' => ['sometimes', 'nullable', 'date'],
            'salesperson_user_id' => ['sometimes', 'nullable', 'uuid'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'lost_reason' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /**
     * Sales Staff own the prospects they raise; only someone who sees
     * every prospect may hand one to another salesperson.
     */
    private function salespersonFor(User $user, ?string $requested, ?string $current = null): ?string
    {
        if (! $user->seesAllProspects()) {
            if ($requested !== null && $requested !== ($current ?? $user->id)) {
                throw new ApiException(403, 'Only the owner, a Sales Manager or a Sales Supervisor can assign a prospect to someone else.');
            }

            return $current ?? $user->id;
        }
        if ($requested === null) {
            return $current ?? $user->id;
        }
        $salesperson = User::find($requested);
        if (! $salesperson || $salesperson->company_id !== $user->company_id) {
            throw new ApiException(422, 'Unknown salesperson');
        }

        return $salesperson->id;
    }

    private function requireLostReason(string $status, ?string $reason): void
    {
        if ($status === Prospect::STATUS_LOST && trim((string) $reason) === '') {
            throw new ApiException(422, 'Give a reason when marking a prospect lost.');
        }
    }

    private function viewer(Request $request): User
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $user;
    }

    private function editor(Request $request): User
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        return $user;
    }
}
