<?php

namespace App\Services\Ai;

use App\Models\AiInteraction;
use App\Models\AiSetting;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Support\Carbon;

/**
 * "Draft with AI" for a Service Record's work description (Dennis,
 * 2026-09-26, decision page: "rough notes in any language become a
 * proper English description to edit and save"). The engineer types
 * notes as they come -- English, Chinese, Malay, shorthand -- and the
 * model returns a clean English description. It only proposes: the
 * text goes back into the box for the engineer to read, change and
 * save; nothing is stored on the Service Record here.
 *
 * Emails and phone numbers are masked first when redaction is on
 * (decision 12.1), and every call is recorded in ai_interactions and
 * Event Logs (decision 12.3), like every other AI feature.
 */
class WorkDescriptionDraft
{
    public const MAX_NOTES_CHARS = 6000;

    /**
     * @return array{interaction: AiInteraction, description: ?string, refused: bool}
     *
     * @throws AiBudgetExceededException
     * @throws AiNotConfiguredException
     * @throws AiException
     */
    public static function draft(User $user, string $notes, ?string $jobOrderId): array
    {
        $settings = AiSetting::current();
        AiBudget::assertWithinCap($user->company_id);
        $text = $settings->redact_personal_data ? Redactor::redact($notes) : $notes;

        $interaction = new AiInteraction([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'feature' => AiInteraction::FEATURE_WORK_DESCRIPTION_DRAFT,
            'entity_type' => $jobOrderId ? 'job_order' : null,
            'entity_id' => $jobOrderId,
            'model' => $settings->model ?: AiSetting::DEFAULT_MODEL,
            'created_at' => Carbon::now(),
        ]);

        try {
            $result = AiClient::complete(self::systemPrompt(), $text, self::schema(), 2048);
        } catch (AiException $e) {
            $interaction->status = AiInteraction::STATUS_ERROR;
            $interaction->error = $e->getMessage();
            $interaction->save();
            throw $e;
        }

        $interaction->model = $result->model;
        $interaction->input_tokens = $result->inputTokens;
        $interaction->output_tokens = $result->outputTokens;
        $description = $result->refused ? null : trim((string) ($result->data['description'] ?? ''));
        if ($description === '') {
            $description = null;
        }
        $interaction->status = $description === null ? AiInteraction::STATUS_REFUSED : AiInteraction::STATUS_OK;
        $interaction->error = $description === null ? ($result->refusalReason ?: 'The assistant gave no description.') : null;
        $interaction->response = $description === null ? null : ['description' => $description];
        $interaction->save();

        Audit::record(
            'ai_interaction', $interaction->id, $description === null ? 'ai_work_description_refused' : 'ai_work_description_drafted', $user->id,
            details: sprintf('model=%s tokens=%d/%d%s', $result->model, $result->inputTokens, $result->outputTokens,
                $jobOrderId ? " job_order={$jobOrderId}" : ''),
        );

        return ['interaction' => $interaction, 'description' => $description, 'refused' => $description === null];
    }

    private static function systemPrompt(): string
    {
        return <<<'TXT'
You help a service engineer write up a Service Record for an IT support company in Singapore.
The message is the engineer's rough notes on the work done at one visit. They may be in any
language or a mix (English, Chinese, Malay, Tamil, Singlish, shorthand).

Write the work description the customer will read, in clear, professional English:
- Keep every fact in the notes: what was reported, checked, found, done, replaced, configured,
  tested, and anything still pending or advised. Keep names of products, models, versions,
  error messages and quantities exactly.
- Never add anything that is not in the notes -- no guessed causes, results or next steps.
- Plain past-tense sentences, or short lines starting with "- " when there are several steps.
- No greeting, no sign-off, no headings.
- Keep placeholders such as [email] or [phone] exactly as they are.
TXT;
    }

    private static function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['description'],
            'properties' => ['description' => ['type' => 'string']],
        ];
    }
}
