<?php

namespace App\Services\Ai;

use App\Models\Contract;
use App\Models\Incident;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\Payment;
use App\Models\PortalUser;
use App\Models\ServiceRecord;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The read-only tools the Customer Helpdesk Portal's chat may call (AI
 * Assistant slice 3, docs/planned-work.md #12 Tier 2 item 6). Every
 * tool is scoped to the portal user's OWN customer -- exactly the
 * queries App\Http\Controllers\Api\PortalController already runs, so
 * a portal chat can never see more than the corresponding portal
 * screen would show. No id is ever accepted from the model as "which
 * customer" -- there is only ever the one, the token's own.
 *
 * Field set mirrors PortalController's presenters exactly, including
 * its "deliberately not exposed" list (design §6): no quotation/rate
 * internals, no GP/cost figures, no other contacts, no raw or
 * deducted minutes. Engineer names are additionally masked here when
 * personal-data masking is on, since sending them to the model
 * provider is a different trust boundary than showing them in the
 * portal's own browser session.
 */
class AiPortalTools
{
    /** @return array<int, array{name: string, description: string, inputSchema: array}> */
    public static function definitions(): array
    {
        return [
            [
                'name' => 'get_my_contracts',
                'description' => 'The customer\'s own service contracts: contracted, consumed and remaining hours, status, kind, start and end dates.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'get_my_job_orders',
                'description' => 'The customer\'s own Job Orders (helpdesk jobs), newest first, optionally filtered to one contract or by status.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'contract_id' => ['type' => 'string', 'description' => 'One of the customer\'s own contract ids, from get_my_contracts'],
                    'status' => ['type' => 'string', 'enum' => ['open', 'assigned', 'closed', 'void']],
                ]],
            ],
            [
                'name' => 'get_my_job_order',
                'description' => 'One of the customer\'s own Job Orders in full, with its Service Records.',
                'inputSchema' => ['type' => 'object', 'properties' => ['job_order_id' => ['type' => 'string']], 'required' => ['job_order_id']],
            ],
            [
                'name' => 'get_my_service_records',
                'description' => 'The customer\'s own Service Records (work logged against their Job Orders), newest first, optionally for one contract.',
                'inputSchema' => ['type' => 'object', 'properties' => ['contract_id' => ['type' => 'string']]],
            ],
            [
                'name' => 'get_my_invoices',
                'description' => 'The customer\'s own Sales Invoices: amount, GST, total, amount paid, outstanding, status, due date.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'get_my_payments',
                'description' => 'The customer\'s own payments (receipts) and which of their invoices each was allocated against.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
            [
                'name' => 'get_my_incidents',
                'description' => 'The customer\'s own Helpdesk Incidents (calls / emails they or their staff raised), newest first, optionally by status.',
                'inputSchema' => ['type' => 'object', 'properties' => ['status' => ['type' => 'string', 'enum' => ['open', 'pending_callback', 'converted', 'closed']]]],
            ],
        ];
    }

    public static function run(string $name, array $input, PortalUser $portalUser, bool $redact): array
    {
        $customerId = $portalUser->contact->customer_id;

        return match ($name) {
            'get_my_contracts' => self::contracts($customerId),
            'get_my_job_orders' => self::jobOrders($customerId, $input),
            'get_my_job_order' => self::jobOrder($customerId, (string) ($input['job_order_id'] ?? ''), $redact),
            'get_my_service_records' => self::serviceRecords($customerId, $input, $redact),
            'get_my_invoices' => self::invoices($customerId),
            'get_my_payments' => self::payments($customerId),
            'get_my_incidents' => self::incidents($customerId, $input),
            default => ['error' => "Unknown tool {$name}."],
        };
    }

    private static function contractNumbers(array $ids): array
    {
        return $ids ? Contract::whereIn('id', $ids)->pluck('contract_number', 'id')->all() : [];
    }

    private static function engineerName(?string $userId, bool $redact): ?string
    {
        if (! $userId) {
            return null;
        }
        $name = User::where('id', $userId)->value('full_name');

        return $name ? ($redact ? '[name]' : $name) : null;
    }

    private static function contracts(string $customerId): array
    {
        $rows = Contract::where('customer_id', $customerId)->orderByDesc('end_date')->get();

        return ['contracts' => $rows->map(fn (Contract $c) => [
            'id' => $c->id,
            'contract_number' => $c->contract_number,
            'kind' => $c->contract_kind,
            'status' => $c->status,
            'contracted_hours' => round(($c->contracted_minutes ?? 0) / 60, 2),
            'consumed_hours' => round(($c->consumed_minutes ?? 0) / 60, 2),
            'remaining_hours' => round($c->remainingMinutes() / 60, 2),
            'start_date' => optional($c->start_date)->toDateString(),
            'end_date' => optional($c->end_date)->toDateString(),
        ])->values()->all()];
    }

    private static function jobOrders(string $customerId, array $input): array
    {
        $q = JobOrder::where('customer_id', $customerId)->orderByDesc('created_at');
        if (! empty($input['contract_id']) && Str::isUuid((string) $input['contract_id'])) {
            $q->where('contract_id', $input['contract_id']); // PORTAL-006
        }
        if (! empty($input['status'])) {
            $q->where('status', (string) $input['status']);
        }
        $rows = $q->limit(25)->get();
        $numbers = self::contractNumbers($rows->pluck('contract_id')->filter()->unique()->all());

        return ['job_orders' => $rows->map(fn (JobOrder $j) => [
            'id' => $j->id,
            'job_order_number' => $j->job_order_number,
            'subject' => $j->subject,
            'status' => $j->status,
            'priority' => $j->priority,
            'contract_id' => $j->contract_id,
            'contract_number' => $j->contract_id ? ($numbers[$j->contract_id] ?? null) : null,
            'due_date' => optional($j->due_date)->toDateString(),
            'created_at' => optional($j->created_at)->toDateString(),
            'closed_at' => optional($j->closed_at)->toDateString(),
        ])->values()->all()];
    }

    private static function jobOrder(string $customerId, string $id, bool $redact): array
    {
        $jo = Str::isUuid($id) ? JobOrder::find($id) : null;
        // A different customer's job order id is "not found", never "forbidden" (design §9.4).
        if (! $jo || $jo->customer_id !== $customerId) {
            return ['error' => 'Job order not found.'];
        }
        $records = ServiceRecord::where('job_order_id', $jo->id)->orderByDesc('work_date')->get();
        $numbers = self::contractNumbers($jo->contract_id ? [$jo->contract_id] : []);

        return [
            'id' => $jo->id,
            'job_order_number' => $jo->job_order_number,
            'subject' => $jo->subject,
            'status' => $jo->status,
            'priority' => $jo->priority,
            'contract_number' => $jo->contract_id ? ($numbers[$jo->contract_id] ?? null) : null,
            'due_date' => optional($jo->due_date)->toDateString(),
            'closed_at' => optional($jo->closed_at)->toDateString(),
            'service_records' => $records->map(fn (ServiceRecord $r) => [
                'work_date' => optional($r->work_date)->toDateString(),
                'engineer_name' => self::engineerName($r->employee_user_id, $redact),
                'minutes' => $r->rounded_minutes,
                'completion_status' => $r->completion_status,
                'status' => $r->status,
            ])->values()->all(),
        ];
    }

    private static function serviceRecords(string $customerId, array $input, bool $redact): array
    {
        $q = ServiceRecord::query()
            ->join('job_orders', 'service_records.job_order_id', '=', 'job_orders.id')
            ->where('job_orders.customer_id', $customerId);
        if (! empty($input['contract_id']) && Str::isUuid((string) $input['contract_id'])) {
            $q->where('job_orders.contract_id', $input['contract_id']);
        }
        $rows = $q->orderByDesc('service_records.work_date')
            ->select(['service_records.*', 'job_orders.job_order_number as jo_number', 'job_orders.contract_id as jo_contract_id'])
            ->limit(25)->get();
        $numbers = self::contractNumbers($rows->pluck('jo_contract_id')->filter()->unique()->all());

        return ['service_records' => $rows->map(fn ($r) => [
            'job_order_number' => $r->jo_number,
            'work_date' => optional($r->work_date)->toDateString(),
            'engineer_name' => self::engineerName($r->employee_user_id, $redact),
            'minutes' => $r->rounded_minutes,
            'completion_status' => $r->completion_status,
            'status' => $r->status,
            'contract_number' => $r->jo_contract_id ? ($numbers[$r->jo_contract_id] ?? null) : null,
        ])->values()->all()];
    }

    private static function invoices(string $customerId): array
    {
        $rows = Invoice::where('customer_id', $customerId)->orderByDesc('issued_at')->limit(25)->get();
        $numbers = self::contractNumbers($rows->pluck('contract_id')->filter()->unique()->all());

        return ['invoices' => $rows->map(fn (Invoice $inv) => [
            'invoice_number' => $inv->invoice_number,
            'description' => $inv->description,
            'contract_number' => $inv->contract_id ? ($numbers[$inv->contract_id] ?? null) : null,
            'total_amount_sgd' => (float) $inv->total_amount_sgd,
            'amount_paid_sgd' => (float) $inv->amount_paid_sgd,
            'outstanding_sgd' => $inv->outstandingSgd()->toFloat(),
            'status' => $inv->status,
            'due_date' => optional($inv->due_date)->toDateString(),
            'issued_at' => optional($inv->issued_at)->toDateString(),
        ])->values()->all()];
    }

    private static function payments(string $customerId): array
    {
        $rows = Payment::with('allocations')->where('customer_id', $customerId)->orderByDesc('payment_date')->limit(25)->get();
        $invoiceIds = $rows->flatMap(fn (Payment $p) => $p->allocations->pluck('invoice_id'))->unique()->all();
        $invoiceNumbers = $invoiceIds ? Invoice::whereIn('id', $invoiceIds)->pluck('invoice_number', 'id')->all() : [];

        return ['payments' => $rows->map(fn (Payment $p) => [
            'voucher_number' => $p->voucher_number,
            'payment_date' => optional($p->payment_date)->toDateString(),
            'amount_sgd' => (float) $p->amount_sgd,
            'method' => $p->method,
            'allocated_to' => $p->allocations->map(fn ($a) => [
                'invoice_number' => $invoiceNumbers[$a->invoice_id] ?? null,
                'amount_sgd' => (float) $a->amount_sgd,
            ])->values()->all(),
        ])->values()->all()];
    }

    private static function incidents(string $customerId, array $input): array
    {
        $q = Incident::where('customer_id', $customerId)->orderByDesc('created_at');
        if (! empty($input['status'])) {
            $q->where('status', (string) $input['status']);
        }
        $rows = $q->limit(25)->get();
        $jobOrderIds = $rows->pluck('converted_job_order_id')->filter()->unique()->all();
        $jobOrderNumbers = $jobOrderIds ? JobOrder::whereIn('id', $jobOrderIds)->pluck('job_order_number', 'id')->all() : [];

        return ['incidents' => $rows->map(fn (Incident $i) => [
            'incident_number' => $i->incident_number,
            'subject' => $i->subject,
            'status' => $i->status,
            'converted_job_order_number' => $i->converted_job_order_id ? ($jobOrderNumbers[$i->converted_job_order_id] ?? null) : null,
            'created_at' => optional($i->created_at)->toDateString(),
        ])->values()->all()];
    }
}
