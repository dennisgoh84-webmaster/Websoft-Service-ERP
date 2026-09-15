<?php

namespace App\Services\Ai;

use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\Incident;
use App\Models\JobOrder;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Services\AccountsReceivableService;
use App\Services\Authority;
use Illuminate\Support\Str;

/**
 * The read-only tools the chat assistant may call (AI Assistant slice
 * 2, docs/planned-work.md #12). Every tool:
 *
 *  - runs AS THE SIGNED-IN USER: it checks the same module key and
 *    access level the corresponding screen checks (Group Authority +
 *    Module Control), and returns a refusal the model relays rather
 *    than data the user could not open themselves;
 *  - is scoped to the user's active company -- an id from another
 *    company is simply "not found";
 *  - reads only, through the existing models and services, and quotes
 *    the figures they compute (hours remaining, aging buckets,
 *    outstanding amounts) -- the model is told never to do that
 *    arithmetic itself;
 *  - masks personal data when Maintenance -> AI Assistant says so
 *    (decision 12.1), since a tool result is sent to the provider too.
 */
class AiTools
{
    public const MAX_ROWS = 25;

    /** @return array<int, array{name: string, description: string, inputSchema: array}> */
    public static function definitions(): array
    {
        $id = fn (string $what) => ['type' => 'string', 'description' => "The $what's id (uuid) from an earlier result or the page context"];

        return [
            [
                'name' => 'find_customers',
                'description' => 'Search the company\'s Companies / Individuals (customers and suppliers) by name. Returns ids to use with the other tools.',
                'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string', 'description' => 'Part of the name']], 'required' => ['query']],
            ],
            [
                'name' => 'get_customer_contracts',
                'description' => 'The service contracts of one customer with contracted, consumed and remaining hours, status, kind, dates and value.',
                'inputSchema' => ['type' => 'object', 'properties' => ['customer_id' => $id('customer')], 'required' => ['customer_id']],
            ],
            [
                'name' => 'get_contract',
                'description' => 'One service contract by id: hours contracted / consumed / remaining, status, kind, dates, value, customer.',
                'inputSchema' => ['type' => 'object', 'properties' => ['contract_id' => $id('contract')], 'required' => ['contract_id']],
            ],
            [
                'name' => 'list_job_orders',
                'description' => 'Job Orders (helpdesk jobs), newest first, optionally for one customer or contract, by status (open, assigned, closed, void), or only those assigned to the person asking.',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'customer_id' => $id('customer'), 'contract_id' => $id('contract'),
                    'status' => ['type' => 'string', 'enum' => ['open', 'assigned', 'closed', 'void']],
                    'assigned_to_me' => ['type' => 'boolean'],
                ]],
            ],
            [
                'name' => 'list_service_records',
                'description' => 'Service Records (time logged against Job Orders), newest first, for one job order or one customer, optionally by status (submitted, approved).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'job_order_id' => $id('job order'), 'customer_id' => $id('customer'),
                    'status' => ['type' => 'string', 'enum' => ['submitted', 'approved']],
                ]],
            ],
            [
                'name' => 'list_incidents',
                'description' => 'Helpdesk Incidents (incoming calls / emails), newest first, optionally for one customer or by status (open, pending_callback, converted, closed).',
                'inputSchema' => ['type' => 'object', 'properties' => [
                    'customer_id' => $id('customer'),
                    'status' => ['type' => 'string', 'enum' => ['open', 'pending_callback', 'converted', 'closed']],
                ]],
            ],
            [
                'name' => 'get_customer_receivables',
                'description' => 'What one customer currently owes: each outstanding invoice with due date, amount outstanding and days overdue, the total, and any unallocated credit. Figures are computed by the Accounts Receivable module.',
                'inputSchema' => ['type' => 'object', 'properties' => ['customer_id' => $id('customer')], 'required' => ['customer_id']],
            ],
            [
                'name' => 'get_ar_aging_summary',
                'description' => 'Accounts Receivable aging for the whole company: per customer, the outstanding amount in each bucket (current, 1-30, 31-60, 61-90, over 90 days) and the total, largest first.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
            ],
        ];
    }

    /**
     * Run one tool as the user. Always returns an array the model can
     * read; access problems come back as ['error' => ...], never as an
     * exception, so the assistant can say so.
     */
    public static function run(string $name, array $input, User $user, bool $redact): array
    {
        return match ($name) {
            'find_customers' => self::guarded($user, 'company_individual_management', fn () => self::findCustomers($user, (string) ($input['query'] ?? ''), $redact)),
            'get_customer_contracts' => self::guarded($user, 'service_contracts', fn () => self::customerContracts($user, (string) ($input['customer_id'] ?? ''))),
            'get_contract' => self::guarded($user, 'service_contracts', fn () => self::contract($user, (string) ($input['contract_id'] ?? ''))),
            'list_job_orders' => self::guarded($user, 'service_operations', fn () => self::jobOrders($user, $input)),
            'list_service_records' => self::guarded($user, 'service_records', fn () => self::serviceRecords($user, $input, $redact)),
            'list_incidents' => self::guarded($user, 'service_operations', fn () => self::incidents($user, $input, $redact)),
            'get_customer_receivables' => self::guarded($user, 'accounts_receivable', fn () => self::receivables($user, (string) ($input['customer_id'] ?? ''))),
            'get_ar_aging_summary' => self::guarded($user, 'accounts_receivable', fn () => self::agingSummary($user)),
            default => ['error' => "Unknown tool {$name}."],
        };
    }

    /** The same gate the screen has: group access level, then Module Control for anyone but the owner. */
    private static function guarded(User $user, string $moduleKey, callable $fn): array
    {
        if (! Authority::hasAccess($user, $moduleKey, 'view')) {
            return ['error' => "Not permitted: the person asking does not have access to the '{$moduleKey}' module, so this information cannot be shown to them."];
        }
        if ($user->role !== User::ROLE_OWNER && ! Authority::isModuleEnabled($user->company_id, $moduleKey)) {
            return ['error' => "Not available: the '{$moduleKey}' module is not enabled for this company."];
        }

        return $fn();
    }

    private static function findCustomers(User $user, string $query, bool $redact): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['error' => 'A search term is required.'];
        }
        $rows = CompanyIndividual::query()
            ->where('company_id', $user->company_id)
            ->where('is_archived', false)
            ->where('name', 'ilike', '%'.str_replace(['%', '_'], ['\\%', '\\_'], $query).'%')
            ->orderBy('name')
            ->limit(15)
            ->get();
        $contractCounts = Contract::query()
            ->whereIn('customer_id', $rows->pluck('id'))
            ->whereIn('status', [Contract::STATUS_ACTIVE, Contract::STATUS_EXCEEDED])
            ->selectRaw('customer_id, count(*) as n')
            ->groupBy('customer_id')
            ->pluck('n', 'customer_id');

        return ['customers' => $rows->map(fn (CompanyIndividual $c) => array_filter([
            'id' => $c->id,
            'name' => $c->name,
            'is_customer' => (bool) $c->is_customer,
            'is_supplier' => (bool) $c->is_supplier,
            'industry' => $c->industry_code,
            'city' => $c->address_city,
            'country' => $c->address_country,
            'contact_person' => $redact ? null : $c->contact_person,
            'phone' => $redact ? null : $c->phone,
            'payment_terms_days' => $c->payment_terms_days,
            'active_contracts' => (int) $contractCounts->get($c->id, 0),
        ], fn ($v) => $v !== null))->values()->all()];
    }

    private static function customer(User $user, string $id): ?CompanyIndividual
    {
        return Str::isUuid($id) ? CompanyIndividual::where('company_id', $user->company_id)->find($id) : null;
    }

    private static function presentContract(Contract $k): array
    {
        return [
            'id' => $k->id,
            'contract_number' => $k->contract_number,
            'customer_id' => $k->customer_id,
            'kind' => $k->contract_kind,
            'status' => $k->status,
            'contracted_hours' => round(($k->contracted_minutes ?? 0) / 60, 2),
            'consumed_hours' => round(($k->consumed_minutes ?? 0) / 60, 2),
            'remaining_hours' => round($k->remainingMinutes() / 60, 2),
            'start_date' => optional($k->start_date)->toDateString(),
            'end_date' => optional($k->end_date)->toDateString(),
            'contract_value_sgd' => $k->contract_value_sgd !== null ? (float) $k->contract_value_sgd : null,
        ];
    }

    private static function customerContracts(User $user, string $customerId): array
    {
        $customer = self::customer($user, $customerId);
        if (! $customer) {
            return ['error' => 'Customer not found.'];
        }
        $contracts = Contract::where('company_id', $user->company_id)->where('customer_id', $customer->id)->orderByDesc('start_date')->limit(self::MAX_ROWS)->get();

        return ['customer_name' => $customer->name, 'contracts' => $contracts->map(fn ($k) => self::presentContract($k))->values()->all()];
    }

    private static function contract(User $user, string $contractId): array
    {
        $k = Str::isUuid($contractId) ? Contract::where('company_id', $user->company_id)->find($contractId) : null;
        if (! $k) {
            return ['error' => 'Contract not found.'];
        }
        $row = self::presentContract($k);
        $row['customer_name'] = CompanyIndividual::where('id', $k->customer_id)->value('name');

        return $row;
    }

    private static function jobOrders(User $user, array $input): array
    {
        $q = JobOrder::where('company_id', $user->company_id)->orderByDesc('created_at');
        if (! empty($input['customer_id'])) {
            $customer = self::customer($user, (string) $input['customer_id']);
            if (! $customer) {
                return ['error' => 'Customer not found.'];
            }
            $q->where('customer_id', $customer->id);
        }
        if (! empty($input['contract_id']) && Str::isUuid((string) $input['contract_id'])) {
            $q->where('contract_id', $input['contract_id']);
        }
        if (! empty($input['status'])) {
            $q->where('status', (string) $input['status']);
        }
        if (! empty($input['assigned_to_me'])) {
            $q->where('assigned_to_user_id', $user->id);
        }
        $rows = $q->limit(self::MAX_ROWS)->get();
        $names = User::whereIn('id', $rows->pluck('assigned_to_user_id')->filter()->unique())->pluck('full_name', 'id');
        $customers = CompanyIndividual::whereIn('id', $rows->pluck('customer_id')->unique())->pluck('name', 'id');

        return ['job_orders' => $rows->map(fn (JobOrder $j) => [
            'id' => $j->id,
            'job_order_number' => $j->job_order_number,
            'subject' => $j->subject,
            'customer_id' => $j->customer_id,
            'customer_name' => $customers->get($j->customer_id),
            'contract_id' => $j->contract_id,
            'type' => $j->job_order_type,
            'billing_classification' => $j->billing_classification,
            'status' => $j->status,
            'priority' => $j->priority,
            'is_urgent' => (bool) $j->is_urgent,
            'assigned_to' => $names->get($j->assigned_to_user_id),
            'due_date' => optional($j->due_date)->toDateString(),
            'created_at' => optional($j->created_at)->toDateString(),
            'closed_at' => optional($j->closed_at)->toDateString(),
        ])->values()->all()];
    }

    private static function serviceRecords(User $user, array $input, bool $redact): array
    {
        $q = ServiceRecord::where('company_id', $user->company_id)->orderByDesc('work_date')->orderByDesc('submitted_at');
        if (! empty($input['job_order_id']) && Str::isUuid((string) $input['job_order_id'])) {
            $q->where('job_order_id', $input['job_order_id']);
        }
        if (! empty($input['customer_id'])) {
            $customer = self::customer($user, (string) $input['customer_id']);
            if (! $customer) {
                return ['error' => 'Customer not found.'];
            }
            $q->whereIn('job_order_id', JobOrder::where('customer_id', $customer->id)->select('id'));
        }
        if (! empty($input['status'])) {
            $q->where('status', (string) $input['status']);
        }
        $rows = $q->limit(self::MAX_ROWS)->get();
        $names = User::whereIn('id', $rows->pluck('employee_user_id')->unique())->pluck('full_name', 'id');
        $jobOrders = JobOrder::whereIn('id', $rows->pluck('job_order_id')->unique())->pluck('job_order_number', 'id');

        return ['service_records' => $rows->map(fn (ServiceRecord $r) => [
            'id' => $r->id,
            'service_record_number' => $r->service_record_number,
            'job_order_id' => $r->job_order_id,
            'job_order_number' => $jobOrders->get($r->job_order_id),
            'work_date' => optional($r->work_date)->toDateString(),
            'employee' => $names->get($r->employee_user_id),
            'raw_minutes' => $r->raw_minutes,
            'rounded_minutes' => $r->rounded_minutes,
            'deducted_minutes' => $r->deducted_minutes,
            'status' => $r->status,
            'outcome' => $r->outcome,
            'completion' => $r->completion_status === ServiceRecord::COMPLETED ? 'completed' : 'uncompleted',
            'is_after_hours' => (bool) $r->is_after_hours,
            'is_late' => $r->isLate(),
            'is_approval_overdue' => $r->isApprovalOverdue(),
            'work_description' => Str::limit((string) ($redact ? Redactor::redact($r->work_description) : $r->work_description), 400, '…') ?: null,
        ])->values()->all()];
    }

    private static function incidents(User $user, array $input, bool $redact): array
    {
        $q = Incident::where('company_id', $user->company_id)->orderByDesc('created_at');
        if (! empty($input['customer_id'])) {
            $customer = self::customer($user, (string) $input['customer_id']);
            if (! $customer) {
                return ['error' => 'Customer not found.'];
            }
            $q->where('customer_id', $customer->id);
        }
        if (! empty($input['status'])) {
            $q->where('status', (string) $input['status']);
        }
        $rows = $q->limit(self::MAX_ROWS)->get();
        $customers = CompanyIndividual::whereIn('id', $rows->pluck('customer_id')->filter()->unique())->pluck('name', 'id');

        return ['incidents' => $rows->map(fn (Incident $i) => [
            'id' => $i->id,
            'incident_number' => $i->incident_number,
            'subject' => $redact ? Redactor::redact($i->subject, array_filter([$i->sender_name])) : $i->subject,
            'description' => Str::limit((string) ($redact ? Redactor::redact($i->description, array_filter([$i->sender_name])) : $i->description), 400, '…') ?: null,
            'customer_id' => $i->customer_id,
            'customer_name' => $customers->get($i->customer_id),
            'source' => $i->source,
            'status' => $i->status,
            'sender_name' => $redact ? ($i->sender_name ? '[name]' : null) : $i->sender_name,
            'sender_email_domain' => Redactor::domainOf($i->sender_email),
            'converted_to' => $i->converted_job_order_id ? 'job_order' : ($i->converted_quotation_id ? 'quotation' : ($i->converted_software_task_id ? 'software_task' : null)),
            'close_reason' => $redact ? Redactor::redact($i->close_reason) : $i->close_reason,
            'created_at' => optional($i->created_at)->toDateString(),
        ])->values()->all()];
    }

    private static function receivables(User $user, string $customerId): array
    {
        $customer = self::customer($user, $customerId);
        if (! $customer) {
            return ['error' => 'Customer not found.'];
        }
        $statement = AccountsReceivableService::buildCustomerStatement($customer, $user->company_id);
        unset($statement['customer_id']);
        $statement['lines'] = array_map(fn ($l) => array_diff_key($l, ['invoice_id' => 1]), $statement['lines']);

        return $statement;
    }

    private static function agingSummary(User $user): array
    {
        [$asAt, $rows] = AccountsReceivableService::agingRows($user->company_id);

        return ['as_at' => $asAt->toDateString(), 'customers' => array_slice($rows, 0, 20), 'currency' => 'SGD'];
    }
}
