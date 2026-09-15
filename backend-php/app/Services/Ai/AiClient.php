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
     * @param  array  $schema  a JSON Schema object for the answer
     *
     * @throws AiNotConfiguredException when no API key is set anywhere
     * @throws AiException when the provider rejects the request or cannot be reached
     */
    public static function complete(string $system, string $user, array $schema, int $maxTokens = 4096, ?string $model = null): AiResult
    {
        $settings = AiSetting::current();
        $model ??= $settings->model ?: AiSetting::DEFAULT_MODEL;

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
