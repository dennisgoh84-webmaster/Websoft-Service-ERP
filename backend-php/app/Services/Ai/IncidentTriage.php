<?php

namespace App\Services\Ai;

use App\Models\AiInteraction;
use App\Models\AiSetting;
use App\Models\CompanyIndividual;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Incident;
use App\Models\JobOrder;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Services\Audit;
use App\Services\IncidentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * AI Assistant slice 1 (docs/planned-work.md #12, Tier 1 items 1 + 2):
 * incident triage and resolution suggestions.
 *
 * Given a logged Incident, the model is shown the company's customers
 * (names, ids and the email domains of their contacts), their valid
 * contracts (number, kind, hours remaining, expiry -- all figures
 * computed by the existing services, never by the model), and the
 * recent resolved incidents with what fixed them (the close reason, or
 * the latest approved Service Record's work description on the Job
 * Order it became). It returns a SUGGESTION: which customer, which
 * contract, what priority, which route (Job Order / Quotation /
 * Software Task / callback / close), the similar past incidents, and a
 * draft reply. Staff apply any of it through the same endpoints they
 * always used -- the assistant proposes, never commits, and does no
 * arithmetic on money or hours.
 *
 * Personal data (sender name / email / phone, contact persons) is
 * masked by App\Services\Ai\Redactor before the text leaves the
 * system, unless the owner turns that off (decision 12.1). Every call
 * is recorded in ai_interactions and the Event Log (decision 12.3).
 */
class IncidentTriage
{
    public const MAX_CUSTOMERS = 300;

    public const MAX_CONTRACTS = 300;

    public const MAX_PAST_INCIDENTS = 40;

    public const ROUTES = ['job_order', 'quotation', 'software_task', 'callback', 'close'];

    /**
     * Run triage and record it. Returns the stored interaction, whose
     * `response` is the suggestion enriched with names (see present()).
     */
    public static function suggest(Incident $incident, User $actor): AiInteraction
    {
        $settings = AiSetting::current();
        AiBudget::assertWithinCap($settings);
        $redact = (bool) $settings->redact_personal_data;

        $context = self::buildContext($incident, $redact);
        $userPrompt = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $interaction = new AiInteraction([
            'company_id' => $incident->company_id,
            'user_id' => $actor->id,
            'feature' => AiInteraction::FEATURE_INCIDENT_TRIAGE,
            'entity_type' => 'incident',
            'entity_id' => $incident->id,
            'model' => $settings->model ?: AiSetting::DEFAULT_MODEL,
            'created_at' => Carbon::now(),
        ]);

        try {
            $result = AiClient::complete(self::systemPrompt(), $userPrompt, self::schema(), 4096);
        } catch (AiException $e) {
            $interaction->status = AiInteraction::STATUS_ERROR;
            $interaction->error = $e->getMessage();
            $interaction->save();
            Audit::record('incident', $incident->id, 'ai_triage_failed', $actor->id, details: $e->getMessage());
            throw $e;
        }

        $interaction->model = $result->model;
        $interaction->input_tokens = $result->inputTokens;
        $interaction->output_tokens = $result->outputTokens;

        if ($result->refused) {
            $interaction->status = AiInteraction::STATUS_REFUSED;
            $interaction->error = $result->refusalReason ?: 'The assistant declined to answer.';
            $interaction->save();
            Audit::record('incident', $incident->id, 'ai_triage_refused', $actor->id, details: $interaction->error);

            return $interaction;
        }

        $interaction->status = AiInteraction::STATUS_OK;
        $interaction->response = self::validate($result->data ?? [], $context, $incident);
        $interaction->save();

        Audit::record(
            'incident', $incident->id, 'ai_triage_suggested', $actor->id,
            details: sprintf(
                'model=%s route=%s customer=%s tokens=%d/%d',
                $result->model,
                $interaction->response['route'] ?? '-',
                $interaction->response['customer_id'] ?? '-',
                $result->inputTokens,
                $result->outputTokens,
            ),
        );

        return $interaction;
    }

    /** The latest suggestion recorded for this incident, if any. */
    public static function latest(Incident $incident): ?AiInteraction
    {
        return AiInteraction::where('company_id', $incident->company_id)
            ->where('feature', AiInteraction::FEATURE_INCIDENT_TRIAGE)
            ->where('entity_id', $incident->id)
            ->orderByDesc('created_at')
            ->first();
    }

    /**
     * What the model is shown. Kept as a plain array so a test can
     * assert exactly what left the system.
     */
    public static function buildContext(Incident $incident, bool $redact): array
    {
        $companyId = $incident->company_id;

        $customers = CompanyIndividual::query()
            ->where('company_id', $companyId)
            ->where('is_customer', true)
            ->where('is_archived', false)
            ->orderBy('name')
            ->limit(self::MAX_CUSTOMERS)
            ->get(['id', 'name', 'contact_person', 'billing_email', 'industry_code']);

        $contactsByCustomer = Contact::query()
            ->whereIn('customer_id', $customers->pluck('id'))
            ->where('is_active', true)
            ->get(['customer_id', 'name', 'email'])
            ->groupBy('customer_id');

        $personalNames = [];
        foreach ($customers as $c) {
            if ($c->contact_person) {
                $personalNames[] = $c->contact_person;
            }
            foreach ($contactsByCustomer->get($c->id, collect()) as $contact) {
                if ($contact->name) {
                    $personalNames[] = $contact->name;
                }
            }
        }
        if ($incident->sender_name) {
            $personalNames[] = $incident->sender_name;
        }

        $customerRows = $customers->map(function (CompanyIndividual $c) use ($contactsByCustomer, $redact) {
            $contacts = $contactsByCustomer->get($c->id, collect());
            $domains = $contacts->map(fn ($x) => Redactor::domainOf($x->email))->filter()->unique()->values();
            $billingDomain = Redactor::domainOf($c->billing_email);
            if ($billingDomain) {
                $domains = $domains->push($billingDomain)->unique()->values();
            }

            return [
                'id' => $c->id,
                'name' => $c->name,
                'industry' => $c->industry_code,
                'email_domains' => $domains->all(),
                'contact_emails' => $redact ? null : $contacts->pluck('email')->filter()->values()->all(),
            ];
        })->values()->all();

        $contracts = Contract::query()
            ->where('company_id', $companyId)
            ->whereIn('status', IncidentService::VALID_CONTRACT_STATUSES)
            ->whereIn('customer_id', $customers->pluck('id'))
            ->orderByDesc('start_date')
            ->limit(self::MAX_CONTRACTS)
            ->get();
        $contractRows = $contracts->map(fn (Contract $k) => [
            'id' => $k->id,
            'customer_id' => $k->customer_id,
            'contract_number' => $k->contract_number,
            'kind' => $k->contract_kind,
            'status' => $k->status,
            'hours_remaining' => round($k->remainingMinutes() / 60, 2),
            'end_date' => optional($k->end_date)->toDateString(),
        ])->values()->all();

        $past = Incident::query()
            ->where('company_id', $companyId)
            ->where('id', '!=', $incident->id)
            ->whereIn('status', [Incident::STATUS_CLOSED, Incident::STATUS_CONVERTED])
            ->orderByDesc('created_at')
            ->limit(self::MAX_PAST_INCIDENTS)
            ->get();
        $jobOrderIds = $past->pluck('converted_job_order_id')->filter()->values();
        $fixes = [];
        if ($jobOrderIds->isNotEmpty()) {
            $records = ServiceRecord::query()
                ->whereIn('job_order_id', $jobOrderIds)
                ->where('status', ServiceRecord::STATUS_APPROVED)
                ->whereNotNull('work_description')
                ->orderByDesc('submitted_at')
                ->get(['job_order_id', 'work_description']);
            foreach ($records as $r) {
                $fixes[$r->job_order_id] ??= $r->work_description;
            }
        }
        $pastRows = $past->map(function (Incident $p) use ($fixes, $redact, $personalNames) {
            $what = null;
            if ($p->converted_job_order_id !== null) {
                $what = $fixes[$p->converted_job_order_id] ?? null;
            }
            $outcome = match (true) {
                $p->converted_job_order_id !== null => 'job_order',
                $p->converted_quotation_id !== null => 'quotation',
                $p->converted_software_task_id !== null => 'software_task',
                default => 'closed',
            };

            return [
                'id' => $p->id,
                'incident_number' => $p->incident_number,
                'customer_id' => $p->customer_id,
                'subject' => self::clip($redact ? Redactor::redact($p->subject, $personalNames) : $p->subject, 200),
                'description' => self::clip($redact ? Redactor::redact($p->description, $personalNames) : $p->description, 400),
                'outcome' => $outcome,
                'close_reason' => self::clip($redact ? Redactor::redact($p->close_reason, $personalNames) : $p->close_reason, 300),
                'what_fixed_it' => self::clip($redact ? Redactor::redact($what, $personalNames) : $what, 400),
            ];
        })->values()->all();

        $emailMatch = IncidentService::tryMatchCustomerByEmail($companyId, $incident->sender_email);

        return [
            'incident' => [
                'id' => $incident->id,
                'incident_number' => $incident->incident_number,
                'source' => $incident->source,
                'subject' => $redact ? Redactor::redact($incident->subject, $personalNames) : $incident->subject,
                'description' => $redact ? Redactor::redact($incident->description, $personalNames) : $incident->description,
                'sender_name' => $redact ? ($incident->sender_name ? '[name]' : null) : $incident->sender_name,
                'sender_email' => $redact ? ($incident->sender_email ? '[email]' : null) : $incident->sender_email,
                'sender_email_domain' => Redactor::domainOf($incident->sender_email),
                'sender_phone' => $redact ? ($incident->sender_phone ? '[phone]' : null) : $incident->sender_phone,
                'customer_id_already_set' => $incident->customer_id,
                'exact_email_match_customer_id' => $emailMatch,
                'logged_at' => optional($incident->created_at)->toIso8601String(),
            ],
            'customers' => $customerRows,
            'valid_contracts' => $contractRows,
            'recent_resolved_incidents' => $pastRows,
            'personal_data_redacted' => $redact,
        ];
    }

    public static function systemPrompt(): string
    {
        return <<<'TXT'
You are the helpdesk triage assistant inside Websoft Service ERP, an IT service company's system in Singapore.
You are given one newly logged incident plus the company's customers, their valid service contracts, and recent resolved incidents, as JSON.

Suggest, for a human to confirm:
1. Which customer the incident belongs to. Use the exact-email match if present; otherwise match by the sender's email domain, the customer name mentioned in the text, or the subject. If nothing fits, say null with confidence "none".
2. Which valid contract to raise a Job Order against, only from the customer's own contracts listed. Prefer the one with hours remaining and the latest end date. Null if none.
3. A priority: low, normal, high or critical, from the wording (outage, cannot work, deadline, all users affected -> higher).
4. A route: "job_order" for support work under a contract, "quotation" for new work or purchases that need pricing, "software_task" for a bug or change in software this company develops, "callback" when the request is unclear and someone must phone the customer, "close" when it needs no action (spam, duplicate, already resolved).
5. Up to three genuinely similar past incidents and what fixed them, quoting the record. Do not invent fixes.
6. A short, polite draft reply to the sender in plain English, acknowledging the request. Do not promise timings or prices. Where a name or contact detail was redacted as [name]/[email]/[phone], write around it.

Never compute hours, money or dates yourself: quote the figures given. Only use ids that appear in the data. Answer with the JSON schema you were given and nothing else.
TXT;
    }

    public static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'summary', 'customer_id', 'customer_confidence', 'customer_reason', 'contract_id',
                'priority', 'route', 'route_reason', 'similar_incidents', 'suggested_reply',
            ],
            'properties' => [
                'summary' => ['type' => 'string', 'description' => 'One sentence restating the request'],
                'customer_id' => ['type' => ['string', 'null']],
                'customer_confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low', 'none']],
                'customer_reason' => ['type' => 'string'],
                'contract_id' => ['type' => ['string', 'null']],
                'priority' => ['type' => 'string', 'enum' => ['low', 'normal', 'high', 'critical']],
                'route' => ['type' => 'string', 'enum' => self::ROUTES],
                'route_reason' => ['type' => 'string'],
                'similar_incidents' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['incident_id', 'why_similar', 'what_fixed_it'],
                        'properties' => [
                            'incident_id' => ['type' => 'string'],
                            'why_similar' => ['type' => 'string'],
                            'what_fixed_it' => ['type' => ['string', 'null']],
                        ],
                    ],
                ],
                'suggested_reply' => ['type' => 'string'],
            ],
        ];
    }

    /**
     * Never trust an id the model returns: anything not in the data it
     * was shown is dropped, and names are attached for the screen.
     */
    private static function validate(array $data, array $context, Incident $incident): array
    {
        $customers = collect($context['customers'])->keyBy('id');
        $contracts = collect($context['valid_contracts'])->keyBy('id');
        $past = collect($context['recent_resolved_incidents'])->keyBy('id');

        $customerId = is_string($data['customer_id'] ?? null) && $customers->has($data['customer_id']) ? $data['customer_id'] : null;
        $contractId = is_string($data['contract_id'] ?? null) && $contracts->has($data['contract_id']) ? $data['contract_id'] : null;
        if ($contractId !== null && $customerId !== null && $contracts[$contractId]['customer_id'] !== $customerId) {
            $contractId = null; // a contract must belong to the suggested customer
        }
        $route = in_array($data['route'] ?? null, self::ROUTES, true) ? $data['route'] : 'callback';
        $priority = in_array($data['priority'] ?? null, [JobOrder::PRIORITY_LOW, JobOrder::PRIORITY_NORMAL, JobOrder::PRIORITY_HIGH, JobOrder::PRIORITY_CRITICAL], true)
            ? $data['priority'] : JobOrder::PRIORITY_NORMAL;

        $similar = [];
        foreach ((array) ($data['similar_incidents'] ?? []) as $s) {
            $id = $s['incident_id'] ?? null;
            if (! is_string($id) || ! $past->has($id)) {
                continue;
            }
            $similar[] = [
                'incident_id' => $id,
                'incident_number' => $past[$id]['incident_number'],
                'subject' => $past[$id]['subject'],
                'why_similar' => (string) ($s['why_similar'] ?? ''),
                'what_fixed_it' => isset($s['what_fixed_it']) && is_string($s['what_fixed_it']) ? $s['what_fixed_it'] : null,
            ];
            if (count($similar) === 3) {
                break;
            }
        }

        return [
            'summary' => (string) ($data['summary'] ?? ''),
            'customer_id' => $customerId,
            'customer_name' => $customerId ? $customers[$customerId]['name'] : null,
            'customer_confidence' => in_array($data['customer_confidence'] ?? null, ['high', 'medium', 'low', 'none'], true) ? $data['customer_confidence'] : 'none',
            'customer_reason' => (string) ($data['customer_reason'] ?? ''),
            'contract_id' => $contractId,
            'contract_number' => $contractId ? $contracts[$contractId]['contract_number'] : null,
            'contract_hours_remaining' => $contractId ? $contracts[$contractId]['hours_remaining'] : null,
            'priority' => $priority,
            'route' => $route,
            'route_reason' => (string) ($data['route_reason'] ?? ''),
            'similar_incidents' => $similar,
            'suggested_reply' => (string) ($data['suggested_reply'] ?? ''),
            'personal_data_redacted' => (bool) $context['personal_data_redacted'],
        ];
    }

    private static function clip(?string $text, int $max): ?string
    {
        if ($text === null) {
            return null;
        }

        return Str::limit(trim($text), $max, '…');
    }
}
