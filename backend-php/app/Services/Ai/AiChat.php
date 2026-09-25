<?php

namespace App\Services\Ai;

use App\Models\AiInteraction;
use App\Models\AiSetting;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\Incident;
use App\Models\JobOrder;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * AI Assistant slice 2 (docs/planned-work.md #12): the chat panel. A
 * staff member asks in any language -- English, 中文, Bahasa Melayu,
 * தமிழ் -- and the assistant answers in the same language, looking
 * things up through App\Services\Ai\AiTools, which run as that user.
 * The conversation is held by the browser and sent whole each time;
 * nothing is stored but the ai_interactions row (tokens, the tools
 * used, the answer) and an Event Log entry.
 */
class AiChat
{
    public const MAX_HISTORY = 20;

    public const MAX_MESSAGE_CHARS = 6000;

    public const MAX_TOOL_ROUNDS = 8;

    public const CONTEXT_TYPES = ['incident', 'customer', 'contract', 'job_order', 'service_records'];

    /**
     * @param  array<int, array{role: string, content: string}>  $history  ending with the user's latest message
     * @param  array{type: string, id?: string|null}|null  $context  what the user is looking at
     * @return array{answer: string, tools_used: array<int, array{name: string, summary: string}>, interaction: AiInteraction, refused: bool}
     *
     * @throws AiValidationException on a malformed history
     */
    public static function reply(User $user, array $history, ?array $context): array
    {
        $history = self::validateHistory($history);
        $settings = AiSetting::current();
        AiBudget::assertWithinCap($settings);
        $redact = (bool) $settings->redact_personal_data;

        $interaction = new AiInteraction([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'feature' => AiInteraction::FEATURE_CHAT,
            'entity_type' => $context['type'] ?? null,
            'entity_id' => isset($context['id']) && Str::isUuid((string) $context['id']) ? $context['id'] : null,
            'model' => $settings->model ?: AiSetting::DEFAULT_MODEL,
            'created_at' => Carbon::now(),
        ]);

        $system = self::systemPrompt($user, $settings, $context, $redact);
        $messages = array_map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']], $history);
        $tools = AiTools::definitions();
        $toolsUsed = [];
        $inputTokens = 0;
        $outputTokens = 0;
        $answer = '';
        $refused = false;

        try {
            for ($round = 0; $round <= self::MAX_TOOL_ROUNDS; $round++) {
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
                if ($round === self::MAX_TOOL_ROUNDS) {
                    $answer = trim((string) $turn->text) ?: 'I looked up as much as I am allowed to in one go and could not finish -- please ask a narrower question.';
                    break;
                }

                $results = [];
                foreach ($turn->toolCalls as $call) {
                    $result = AiTools::run($call['name'], $call['input'], $user, $redact);
                    $toolsUsed[] = ['name' => $call['name'], 'summary' => self::summarise($call['name'], $call['input'], $result)];
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
        $interaction->response = ['answer' => $answer, 'tools_used' => $toolsUsed, 'question_chars' => strlen(end($history)['content'])];
        $interaction->save();

        Audit::record(
            'ai_chat', $interaction->id, $refused ? 'ai_chat_refused' : 'ai_chat_answered', $user->id,
            details: sprintf('context=%s tools=%s tokens=%d/%d', $context['type'] ?? '-', implode(',', array_column($toolsUsed, 'name')) ?: '-', $inputTokens, $outputTokens),
        );

        return ['answer' => $answer, 'tools_used' => $toolsUsed, 'interaction' => $interaction, 'refused' => $refused];
    }

    /** @return array<int, array{role: string, content: string}> */
    public static function validateHistory(array $history): array
    {
        $clean = [];
        foreach ($history as $m) {
            $role = $m['role'] ?? null;
            $content = $m['content'] ?? null;
            if (! in_array($role, ['user', 'assistant'], true) || ! is_string($content) || trim($content) === '') {
                throw new AiValidationException('Each message needs a role of user or assistant and non-empty text.');
            }
            if (mb_strlen($content) > self::MAX_MESSAGE_CHARS) {
                throw new AiValidationException('A message is too long (limit '.self::MAX_MESSAGE_CHARS.' characters).');
            }
            $clean[] = ['role' => $role, 'content' => $content];
        }
        if ($clean === [] || end($clean)['role'] !== 'user') {
            throw new AiValidationException('The conversation must end with the user\'s message.');
        }
        // Keep the tail; the API wants alternating turns starting with the user.
        $clean = array_slice($clean, -self::MAX_HISTORY);
        while ($clean !== [] && $clean[0]['role'] !== 'user') {
            array_shift($clean);
        }

        return array_values($clean);
    }

    public static function systemPrompt(User $user, AiSetting $settings, ?array $context, bool $redact): string
    {
        $company = Company::find($user->company_id);
        $today = Carbon::now('Asia/Singapore')->toFormattedDateString();
        $name = $settings->assistantName();
        $contextLine = self::describeContext($user, $context);

        $text = <<<TXT
You are {$name}, the assistant built into Websoft Service ERP, the business system of {$company?->name} (an IT services company in Singapore). Today is {$today} (Singapore time).
You are talking to {$user->full_name} (role: {$user->role}).

LANGUAGE: reply in the language the person wrote in -- English, 中文 (Simplified Chinese), Bahasa Melayu, தமிழ் or any other. If they mix languages, follow the main one. When asked to draft something for a customer, write it in the language the customer uses if that is clear, otherwise in the language of the request. Keep the system's own record numbers (INC-..., JO-..., CON-...) exactly as they are.

WHAT YOU CAN DO: answer questions about customers, service contracts and their hours, job orders, service records, helpdesk incidents and receivables, by calling the tools. The tools run with the permissions of the person asking; if a tool says "Not permitted", tell them plainly that they do not have access to that, and do not guess. You cannot change any record: if asked to create, edit, approve, close or send something, explain which screen or button does it.

FIGURES: quote numbers exactly as the tools return them (hours remaining, amounts outstanding, days overdue, aging buckets). Never add, subtract or convert them yourself; if a total is not in the data, say it is not available rather than computing it. Amounts are in SGD.

STYLE: concise and concrete -- answer first, then the supporting records with their numbers. Use short lists for several items. Do not invent records; only refer to ids and numbers that appeared in tool results or the page context.
TXT;
        if ($contextLine !== null) {
            $text .= "\n\nCONTEXT: the person is currently looking at {$contextLine}. Prefer that record when a question is ambiguous.";
        }
        if ($redact) {
            $text .= "\n\nPRIVACY: personal data in the records (people's names, email addresses, telephone numbers) has been masked as [name] / [email] / [phone] before reaching you; work around it and do not ask for it.";
        }

        return $text;
    }

    /** One line naming the record on screen, company-scoped, or null. */
    public static function describeContext(User $user, ?array $context): ?string
    {
        $type = $context['type'] ?? null;
        $id = $context['id'] ?? null;
        if (! in_array($type, self::CONTEXT_TYPES, true)) {
            return null;
        }
        if ($type === 'service_records') {
            return 'the Service Records list';
        }
        if (! is_string($id) || ! Str::isUuid($id)) {
            return null;
        }
        $companyId = $user->company_id;

        return match ($type) {
            'incident' => ($i = Incident::where('company_id', $companyId)->find($id))
                ? "Incident {$i->incident_number} (id {$i->id}), subject \"{$i->subject}\", status {$i->status}".($i->customer_id ? ", customer id {$i->customer_id}" : '')
                : null,
            'customer' => ($c = CompanyIndividual::where('company_id', $companyId)->find($id))
                ? "Company / Individual \"{$c->name}\" (customer id {$c->id})"
                : null,
            'contract' => ($k = Contract::where('company_id', $companyId)->find($id))
                ? "Contract {$k->contract_number} (id {$k->id}, customer id {$k->customer_id}, status {$k->status})"
                : null,
            'job_order' => ($j = JobOrder::where('company_id', $companyId)->find($id))
                ? "Job Order {$j->job_order_number} (id {$j->id}), subject \"{$j->subject}\", status {$j->status}, customer id {$j->customer_id}".($j->contract_id ? ", contract id {$j->contract_id}" : '')
                : null,
            default => null,
        };
    }

    private static function summarise(string $name, array $input, array $result): string
    {
        if (isset($result['error'])) {
            return $result['error'];
        }
        $count = null;
        foreach (['customers', 'contracts', 'job_orders', 'service_records', 'incidents', 'lines'] as $key) {
            if (isset($result[$key]) && is_array($result[$key])) {
                $count = count($result[$key]);
                break;
            }
        }
        $label = str_replace('_', ' ', $name);
        $arg = $input['query'] ?? $input['status'] ?? null;

        return trim($label.($arg ? " \"{$arg}\"" : '').($count !== null ? " -- {$count} row".($count === 1 ? '' : 's') : ''));
    }
}

class AiValidationException extends \RuntimeException {}
