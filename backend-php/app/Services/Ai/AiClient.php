<?php

namespace App\Services\Ai;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use App\Models\AiSetting;

/**
 * The one place this system talks to the model provider (Anthropic's
 * official PHP SDK). Every AI feature goes through complete(), which
 * asks for a structured JSON answer against a schema the caller owns,
 * so a feature never parses free text. Configuration comes from
 * App\Models\AiSetting (Maintenance -> AI Assistant), with
 * ANTHROPIC_API_KEY in .env as the bootstrap fallback.
 *
 * TESTS: fake() replaces the transport with a queue of canned answers
 * and records what would have been sent, so a test can assert on the
 * prompt (e.g. that personal data was redacted) without a network.
 *
 * Model: claude-opus-5 by default (AiSetting::DEFAULT_MODEL); adaptive
 * thinking is the model's default and is left on. A refusal
 * (stop_reason "refusal") is returned as AiResult::$refused rather
 * than thrown, so the caller can tell the user the assistant declined.
 *
 * Fallback model (Backlog 2, 2026-09-26): when one is set under
 * Maintenance -> AI Assistant, a call that fails at the provider
 * (rejected, overloaded, unreachable) or is declined is tried once more
 * on the fallback model; the answer then carries the fallback's name,
 * so the interaction record shows which model answered, and a declined
 * first try's tokens are added in. Not tried for a missing key or a
 * reached cap -- the fallback would fail the same way. A caller that
 * names a model explicitly gets that model only.
 */
class AiClient
{
    /** @var array<int, array>|null queued fake answers, newest last */
    private static ?array $fakeQueue = null;

    /** @var array<int, array> every request made while faked */
    private static array $fakeRequests = [];

    public static function fake(array $answers = []): void
    {
        self::$fakeQueue = $answers;
        self::$fakeRequests = [];
    }

    /** @return array<int, array{system: string, user: string, schema: array, model: string}> */
    public static function requests(): array
    {
        return self::$fakeRequests;
    }

    public static function restore(): void
    {
        self::$fakeQueue = null;
        self::$fakeRequests = [];
    }

    public static function isFaked(): bool
    {
        return self::$fakeQueue !== null;
    }

    /**
     * One turn of a tool-using conversation. `$messages` is the full
     * history in the API's shape (user / assistant / tool results);
     * `$tools` are definitions with camelCase `inputSchema`. The model
     * may answer with text, with tool calls, or both.
     *
     * Fake protocol: each queued answer is either ['text' => '...'] or
     * ['tool_calls' => [['name' => ..., 'input' => [...]], ...]] (an id
     * is made up), plus the same __error / __refused / __*_tokens keys
     * complete() accepts. Every request is recorded with its system
     * prompt, messages and tool names.
     *
     * @throws AiNotConfiguredException
     * @throws AiException
     */
    public static function chat(string $system, array $messages, array $tools, int $maxTokens = 4096, ?string $model = null): AiTurn
    {
        $settings = AiSetting::current();
        $primary = $model ?? ($settings->model ?: AiSetting::DEFAULT_MODEL);
        $fallback = $model === null ? $settings->fallbackModelFor($primary) : null;

        try {
            $turn = self::chatWith($settings, $system, $messages, $tools, $maxTokens, $primary);
        } catch (AiException $e) {
            if ($fallback === null) {
                throw $e;
            }

            return self::chatWith($settings, $system, $messages, $tools, $maxTokens, $fallback);
        }
        if ($turn->refused && $fallback !== null) {
            $second = self::chatWith($settings, $system, $messages, $tools, $maxTokens, $fallback);

            return new AiTurn(
                $second->text, $second->toolCalls, $second->assistantContent, $second->stopReason, $second->model,
                $second->inputTokens + $turn->inputTokens, $second->outputTokens + $turn->outputTokens,
                $second->refused, $second->refusalReason,
            );
        }

        return $turn;
    }

    private static function chatWith(AiSetting $settings, string $system, array $messages, array $tools, int $maxTokens, string $model): AiTurn
    {

        if (self::$fakeQueue !== null) {
            self::$fakeRequests[] = ['system' => $system, 'messages' => $messages, 'tools' => array_column($tools, 'name'), 'model' => $model];
            $answer = array_shift(self::$fakeQueue);
            if ($answer === null) {
                throw new AiException('AiClient::fake() has no answer queued for this call.');
            }
            if (($answer['__error'] ?? null) !== null) {
                throw new AiException($answer['__error']);
            }
            if (($answer['__refused'] ?? false) === true) {
                return new AiTurn(null, [], [], 'refusal', $model, 0, 0, true, $answer['__reason'] ?? null);
            }
            $content = [];
            $calls = [];
            if (isset($answer['text'])) {
                $content[] = ['type' => 'text', 'text' => $answer['text']];
            }
            foreach ($answer['tool_calls'] ?? [] as $i => $call) {
                $id = 'toolu_fake_'.count(self::$fakeRequests).'_'.$i;
                $calls[] = ['id' => $id, 'name' => $call['name'], 'input' => $call['input'] ?? []];
                $content[] = ['type' => 'tool_use', 'id' => $id, 'name' => $call['name'], 'input' => $call['input'] ?? []];
            }

            return new AiTurn(
                $answer['text'] ?? null, $calls, $content, $calls ? 'tool_use' : 'end_turn', $model,
                $answer['__input_tokens'] ?? 100, $answer['__output_tokens'] ?? 50, false, null,
            );
        }

        $apiKey = $settings->effectiveApiKey();
        if ($apiKey === null) {
            throw new AiNotConfiguredException(
                'The AI Assistant has no API key. Set one under Maintenance -> AI Assistant (or ANTHROPIC_API_KEY in .env).'
            );
        }

        $client = new Client(apiKey: $apiKey, requestOptions: ['timeout' => 120]);

        try {
            $message = $client->messages->create(
                maxTokens: $maxTokens,
                messages: $messages,
                model: $model,
                system: $system,
                tools: $tools,
            );
        } catch (APIStatusException $e) {
            throw new AiException('The model provider rejected the request: '.$e->getMessage());
        } catch (APIConnectionException $e) {
            throw new AiException('Could not reach the model provider: '.$e->getMessage());
        }

        $inputTokens = (int) ($message->usage->inputTokens ?? 0);
        $outputTokens = (int) ($message->usage->outputTokens ?? 0);
        if ($message->stopReason === 'refusal') {
            return new AiTurn(null, [], [], 'refusal', $model, $inputTokens, $outputTokens, true, $message->stopDetails?->explanation ?? null);
        }

        $text = null;
        $calls = [];
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text = ($text ?? '').$block->text;
            } elseif ($block->type === 'tool_use') {
                $calls[] = ['id' => $block->id, 'name' => $block->name, 'input' => (array) $block->input];
            }
        }

        return new AiTurn(
            $text, $calls, $message->content, (string) $message->stopReason, $model,
            $inputTokens, $outputTokens, false, null,
        );
    }

    /**
     * @param  array  $schema  a JSON Schema object for the answer
     *
     * @throws AiNotConfiguredException when no API key is set anywhere
     * @throws AiException when the provider rejects the request or cannot be reached
     */
    public static function complete(string $system, string $user, array $schema, int $maxTokens = 4096, ?string $model = null): AiResult
    {
        $settings = AiSetting::current();
        $primary = $model ?? ($settings->model ?: AiSetting::DEFAULT_MODEL);
        $fallback = $model === null ? $settings->fallbackModelFor($primary) : null;

        try {
            $result = self::completeWith($settings, $system, $user, $schema, $maxTokens, $primary);
        } catch (AiException $e) {
            if ($fallback === null) {
                throw $e;
            }

            return self::completeWith($settings, $system, $user, $schema, $maxTokens, $fallback);
        }
        if ($result->refused && $fallback !== null) {
            $second = self::completeWith($settings, $system, $user, $schema, $maxTokens, $fallback);

            return new AiResult(
                $second->data, $second->model, $second->inputTokens + $result->inputTokens,
                $second->outputTokens + $result->outputTokens, $second->refused, $second->refusalReason,
            );
        }

        return $result;
    }

    private static function completeWith(AiSetting $settings, string $system, string $user, array $schema, int $maxTokens, string $model): AiResult
    {

        if (self::$fakeQueue !== null) {
            self::$fakeRequests[] = ['system' => $system, 'user' => $user, 'schema' => $schema, 'model' => $model];
            $answer = array_shift(self::$fakeQueue);
            if ($answer === null) {
                throw new AiException('AiClient::fake() has no answer queued for this call.');
            }
            if (($answer['__error'] ?? null) !== null) {
                throw new AiException($answer['__error']);
            }
            if (($answer['__refused'] ?? false) === true) {
                return new AiResult(null, $model, 0, 0, true, $answer['__reason'] ?? null);
            }

            return new AiResult($answer, $model, $answer['__input_tokens'] ?? 100, $answer['__output_tokens'] ?? 50, false, null);
        }

        $apiKey = $settings->effectiveApiKey();
        if ($apiKey === null) {
            throw new AiNotConfiguredException(
                'The AI Assistant has no API key. Set one under Maintenance -> AI Assistant (or ANTHROPIC_API_KEY in .env).'
            );
        }

        $client = new Client(apiKey: $apiKey, requestOptions: ['timeout' => 120]);

        try {
            $message = $client->messages->create(
                maxTokens: $maxTokens,
                messages: [['role' => 'user', 'content' => $user]],
                model: $model,
                system: $system,
                outputConfig: ['format' => ['type' => 'json_schema', 'schema' => $schema]],
            );
        } catch (APIStatusException $e) {
            throw new AiException('The model provider rejected the request: '.$e->getMessage());
        } catch (APIConnectionException $e) {
            throw new AiException('Could not reach the model provider: '.$e->getMessage());
        }

        $inputTokens = (int) ($message->usage->inputTokens ?? 0);
        $outputTokens = (int) ($message->usage->outputTokens ?? 0);

        if ($message->stopReason === 'refusal') {
            $reason = $message->stopDetails?->explanation ?? null;

            return new AiResult(null, $model, $inputTokens, $outputTokens, true, $reason);
        }

        $text = null;
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text = $block->text;
                break;
            }
        }
        $data = is_string($text) ? json_decode($text, true) : null;
        if (! is_array($data)) {
            throw new AiException('The model returned no structured answer'.($message->stopReason === 'max_tokens' ? ' (output was cut off)' : '').'.');
        }

        return new AiResult($data, $model, $inputTokens, $outputTokens, false, null);
    }
}

/**
 * One assistant turn of a tool-using conversation (see AiChat). The
 * caller appends `assistantContent` verbatim as the next assistant
 * message and answers every tool call with a tool_result before the
 * next turn.
 */
class AiTurn
{
    /**
     * @param  array<int, array{id: string, name: string, input: array}>  $toolCalls
     * @param  mixed  $assistantContent  the content blocks to replay as the assistant message
     */
    public function __construct(
        public readonly ?string $text,
        public readonly array $toolCalls,
        public readonly mixed $assistantContent,
        public readonly string $stopReason,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly bool $refused,
        public readonly ?string $refusalReason,
    ) {}
}

class AiResult
{
    public function __construct(
        public readonly ?array $data,
        public readonly string $model,
        public readonly int $inputTokens,
        public readonly int $outputTokens,
        public readonly bool $refused,
        public readonly ?string $refusalReason,
    ) {}
}

class AiNotConfiguredException extends \RuntimeException {}
class AiException extends \RuntimeException {}
