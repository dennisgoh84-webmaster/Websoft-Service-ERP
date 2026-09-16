<?php

namespace App\Services\Ai;

use App\Models\AiInteraction;
use App\Models\AiSetting;
use Illuminate\Support\Carbon;

/**
 * A monthly spending cap for the AI Assistant (Dennis, 2026-09-16,
 * settling decision 12.2: "a spending cap, once you've seen a month
 * of real usage... yes pls proceed to build in the settings").
 *
 * Built in TOKENS, not SGD: this system measures and displays tokens
 * (see AiAssistantController::usage()), but has no live provider
 * pricing feed, and Anthropic's per-token rate differs by model and
 * can change -- hardcoding a price table here would be inventing a
 * figure nobody gave us (CLAUDE.md: "never assume a business rule
 * when requirements have not been provided"), and a stale one could
 * silently under- or over-estimate real spend. The token count is the
 * one number this system can state honestly; see open-business-
 * decisions.md #44 for the reasoning and how to move to a currency
 * figure later (a per-model SGD/1M-token table, entered once Dennis
 * has real invoices to calibrate it against).
 *
 * The cap is INSTALLATION-wide, not per company, matching AiSetting
 * itself (one shared API key, one bill) -- checked ONCE at the start
 * of a request (an incident triage, a chat turn, a connection test),
 * not on every tool-use round inside a single chat reply, so a
 * capped account gets a clean refusal before the next question rather
 * than a conversation that dies partway through answering one.
 */
class AiBudget
{
    /**
     * @throws AiBudgetExceededException if this calendar month's
     *                                   recorded usage has already reached the configured cap
     */
    public static function assertWithinCap(?AiSetting $settings = null): void
    {
        $settings ??= AiSetting::current();
        $cap = $settings->monthly_token_cap;
        if ($cap === null || $cap <= 0) {
            return; // no cap set -- the default, unlimited as before
        }

        $used = self::tokensUsedThisMonth();
        if ($used >= $cap) {
            throw new AiBudgetExceededException(
                "The AI Assistant's monthly token cap ({$cap}) has been reached (".number_format($used).
                ' used so far this month) -- no further calls will be made until next month, or the cap is '.
                'raised under Maintenance -> AI Assistant.'
            );
        }
    }

    /** Same calendar-month boundary (Asia/Singapore) as the Usage tile on the settings screen -- the two must never disagree. */
    public static function tokensUsedThisMonth(): int
    {
        $monthStart = Carbon::now('Asia/Singapore')->startOfMonth()->utc();
        $query = AiInteraction::where('created_at', '>=', $monthStart);

        return (int) ((clone $query)->sum('input_tokens') + (clone $query)->sum('output_tokens'));
    }
}

/**
 * Deliberately NOT a subclass of AiException: like AiNotConfiguredException,
 * this must never be caught by a caller's `catch (AiException $e)` (which
 * records a failed ai_interactions row) -- a capped call never reaches the
 * provider, so there is nothing to record, only to refuse.
 */
class AiBudgetExceededException extends \RuntimeException {}
