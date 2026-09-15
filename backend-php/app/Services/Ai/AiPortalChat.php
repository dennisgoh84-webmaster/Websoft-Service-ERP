<?php

namespace App\Services\Ai;

use App\Models\AiInteraction;
use App\Models\AiSetting;
use App\Models\Company;
use App\Models\Contract;
use App\Models\JobOrder;
use App\Models\PortalUser;
use App\Services\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * AI Assistant slice 3 (docs/planned-work.md #12, Tier 2 item 6): the
 * chat panel on the Customer Helpdesk Portal. Same shape as
 * App\Services\Ai\AiChat (the staff chat), deliberately kept separate
 * rather than parameterised over "who is asking": the portal is a
 * genuinely different auth realm and a narrower, fixed scope (App\
 * Services\Ai\AiPortalTools, always the token's own customer, never an
 * id chosen by the model), and PORTAL-003's own rule against a
 * relaxed mode of the staff path applies here just as much as to
 * authentication itself.
 *
 * The customer is a customer, never a colleague: the system prompt
 * says so explicitly, and the assistant can only look things up --
 * raising an Incident is still the customer's own action on the
 * Incidents tab (propose, never commit, same as every other slice).
 */
class AiPortalChat
{
    public const CONTEXT_TYPES = ['contract', 'job_order', 'billing', 'incidents'];

    /**
     * @param  array<int, array{role: string, content: string}>  $history
     * @return array{answer: string, tools_used: array<int, array{name: string, summary: string}>, interaction: AiInteraction, refused: bool}
     */
    public static function reply(PortalUser $portalUser, array $history, ?array $context): array
    {
        $history = AiChat::validateHistory($history);
        $settings = AiSetting::current();
        $redact = (bool) $settings->redact_personal_data;

        $interaction = new AiInteraction([
            'company_id' => $portalUser->company_id,
            'portal_user_id' => $portalUser->id,
            'feature' => AiInteraction::FEATURE_PORTAL_CHAT,
            'entity_type' => $context['type'] ?? null,
            'entity_id' => isset($context['id']) && Str::isUuid((string) $context['id']) ? $context['id'] : null,
            'model' => $settings->model ?: AiSetting::DEFAULT_MODEL,
            'created_at' => Carbon::now('UTC'),
        ]);

        $system = self::systemPrompt($portalUser, $settings, $context);
        $messages = array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $history);
        $tools = AiPortalTools::definitions();
        $toolsUsed = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $answer = '';
        $refused = false;

        try {
            for ($round = 0; $round <= AiChat::MAX_TOOL_ROUNDS; $round++) {
                $turn = AiClient::chat($system, $messages, $tools, 4096);
                $inputTokens += $turn->inputTokens;
                $outputTokens += $turn->outputTokens;
                $interaction->model = $turn->model;

                if ($turn->refused) {
                    $refused = true;
                    $answer = $turn->refusalReason ?: 'The assistant declined to answer that.';
                    break;
                }
                if ($turn->toolCalls === []) {
                    $answer = trim((string) $turn->text);
                    break;
                }
                if ($round === AiChat::MAX_TOOL_ROUNDS) {
                    $answer = trim((string) $turn->text) ?: 'I looked up as much as I am allowed to in one go and could not finish -- please ask a narrower question.';
                    break;
                }

                $results = [];
                foreach ($turn->toolCalls as $call) {
                    $result = AiPortalTools::run($call['name'], $call['input'], $portalUser, $redact);
                    $toolsUsed[] = ['name' => $call['name'], 'summary' => self::summarise($call['name'], $result)];
                    $results[] = [
                        'type' => 'tool_result',
                        'toolUseID' => $call['id'],
                        'content' => json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                        'isError' => isset($result['error']),
                    ];
                }
                $messages[] = ['role' => 'assistant', 'content' => $turn->assistantContent];
                $messages[] = ['role' => 'user', 'content' => $results];
            }
        } catch (AiException $e) {
            $interaction->status = AiInteraction::STATUS_ERROR;
            $interaction->error = $e->getMessage();
            $interaction->input_tokens = $inputTokens;
            $interaction->output_tokens = $outputTokens;
            $interaction->save();
            throw $e;
        }

        $interaction->status = $refused ? AiInteraction::STATUS_REFUSED : AiInteraction::STATUS_OK;
        $interaction->error = $refused ? $answer : null;
        $interaction->input_tokens = $inputTokens;
        $interaction->output_tokens = $outputTokens;
        $interaction->response = ['answer' => $answer, 'tools_used' => $toolsUsed];
        $interaction->save();

        $contact = $portalUser->contact;
        Audit::record(
            'ai_chat', $interaction->id, $refused ? 'ai_portal_chat_refused' : 'ai_portal_chat_answered',
            actorUserId: null,
            actorName: ($contact?->name ?? $portalUser->email).' (portal)',
            companyId: $portalUser->company_id,
            details: sprintf('tools=%s tokens=%d/%d', implode(',', array_column($toolsUsed, 'name')) ?: '-', $inputTokens, $outputTokens),
        );

        return ['answer' => $answer, 'tools_used' => $toolsUsed, 'interaction' => $interaction, 'refused' => $refused];
    }

    public static function systemPrompt(PortalUser $portalUser, AiSetting $settings, ?array $context): string
    {
        $company = Company::find($portalUser->company_id);
        $customerName = $portalUser->contact?->customer?->name ?? 'your account';
        $today = Carbon::now('Asia/Singapore')->toFormattedDateString();
        $name = $settings->assistantName();
        $contextLine = self::describeContext($portalUser, $context);

        $text = <<<TXT
You are {$name}, the assistant on {$company?->name}'s customer helpdesk portal (an IT services company in Singapore). Today is {$today} (Singapore time).
You are talking to a customer contact from {$customerName}, signed in to their own account on the portal -- NOT a member of staff. Never suggest you are speaking to, or acting as, a staff member; you are their AI assistant only.

LANGUAGE: reply in the language they write in -- English, 中文 (Simplified Chinese), Bahasa Melayu, தமிழ் or any other. Keep record numbers (JO-..., CON-..., INV-...) exactly as they are.

WHAT YOU CAN DO: answer questions about THIS CUSTOMER'S OWN contracts and remaining hours, job orders, service records, invoices and payments, and helpdesk incidents, by calling the tools -- every tool already returns only this customer's own records, so you never need to and never should ask which customer. You cannot see any other customer's information, and you cannot change any record. If asked to raise a new incident, tell them to use the Incidents tab -- you can help them word the request, but you do not create it yourself.

FIGURES: quote hours, amounts and dates exactly as the tools return them. Never add, subtract or convert them yourself. Amounts are in SGD.

STYLE: friendly, concise, and concrete. Do not invent records; only refer to numbers that appeared in tool results.
TXT;
        if ($contextLine !== null) {
            $text .= "\n\nCONTEXT: they are currently looking at {$contextLine}. Prefer that record when a question is ambiguous.";
        }

        return $text;
    }

    private static function describeContext(PortalUser $portalUser, ?array $context): ?string
    {
        $type = $context['type'] ?? null;
        $id = $context['id'] ?? null;
        if (! in_array($type, self::CONTEXT_TYPES, true)) {
            return null;
        }
        if (in_array($type, ['billing', 'incidents'], true)) {
            return $type === 'billing' ? 'their Billing tab (invoices and payments)' : 'their Incidents tab';
        }
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }
        $customerId = $portalUser->contact->customer_id;

        return match ($type) {
            'contract' => ($k = Contract::where('customer_id', $customerId)->find($id))
                ? "Contract {$k->contract_number} (status {$k->status})" : null,
            'job_order' => ($j = JobOrder::where('customer_id', $customerId)->find($id))
                ? "Job Order {$j->job_order_number}, subject \"{$j->subject}\", status {$j->status}" : null,
            default => null,
        };
    }

    private static function summarise(string $name, array $result): string
    {
        if (isset($result['error'])) {
            return $result['error'];
        }
        $count = null;
        foreach (['contracts', 'job_orders', 'service_records', 'invoices', 'payments', 'incidents'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                $count = count($result[$key]);
                break;
            }
        }
        $label = str_replace('_', ' ', $name);

        return trim($label.($count !== null ? " -- {$count} row".($count === 1 ? '' : 's') : ''));
    }
}
