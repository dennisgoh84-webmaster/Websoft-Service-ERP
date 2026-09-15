<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\AiInteraction;
use App\Models\AiSetting;
use App\Models\Incident;
use App\Models\User;
use App\Services\Ai\AiChat;
use App\Services\Ai\AiClient;
use App\Services\Ai\AiException;
use App\Services\Ai\AiNotConfiguredException;
use App\Services\Ai\AiValidationException;
use App\Services\Ai\IncidentTriage;
use App\Services\Audit;
use App\Services\Authority;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * AI Assistant (docs/planned-work.md #12, slice 1 built 2026-09-15).
 *
 * Settings and usage live under Core / Administration (Maintenance ->
 * AI Assistant). The features themselves are gated on the
 * `ai_assistant` module key, which Module Control treats as a paid
 * add-on (decision 12.4): unlike other modules the OWNER is gated too,
 * because the key is a licence, not a permission -- see
 * requireLicensed().
 */
class AiAssistantController extends Controller
{
    private const ADMIN_MODULE = 'core_administration';

    public const MODULE = 'ai_assistant';

    private const SETTINGS_AUDIT_ID = '00000000-0000-0000-0000-000000000004';

    private const MAX_AVATAR_CHARS = 400_000; // ~300 KB of base64, same ceiling as the company logo

    // ---- Settings -------------------------------------------------

    public function settings(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::ADMIN_MODULE, 'view');

        return response()->json($this->presentSettings());
    }

    public function updateSettings(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::ADMIN_MODULE, 'full');

        $fields = $request->validate([
            'api_key' => 'sometimes|nullable|string|max:500',
            'model' => 'sometimes|string|max:60|regex:/^[a-z0-9\-.]+$/',
            'redact_personal_data' => 'sometimes|boolean',
            'assistant_name' => 'sometimes|string|min:1|max:40',
            'assistant_avatar' => 'sometimes|nullable|string',
        ]);
        if (array_key_exists('assistant_avatar', $fields) && $fields['assistant_avatar'] !== null) {
            $avatar = $fields['assistant_avatar'];
            if (! preg_match('#^data:image/(png|jpeg|webp|gif);base64,[A-Za-z0-9+/=]+$#', $avatar)) {
                throw new ApiException(422, 'The avatar must be a PNG, JPEG, WebP or GIF image.');
            }
            if (strlen($avatar) > self::MAX_AVATAR_CHARS) {
                throw new ApiException(422, 'The avatar image is too large (about 300 KB at most).');
            }
        }

        $row = AiSetting::current();
        $oldValue = [];
        $newValue = [];
        foreach ($fields as $field => $new) {
            $old = $row->{$field};
            if ($field === 'api_key') {
                $new = $new === '' ? null : $new;
                if ($old == $new) {
                    continue;
                }
                // Record THAT it changed, never the credential itself.
                $oldValue[$field] = $old ? '(set)' : '(none)';
                $newValue[$field] = $new ? '(set)' : '(none)';
            } elseif ($field === 'assistant_avatar') {
                if ($old == $new) {
                    continue;
                }
                $oldValue[$field] = $old ? '(image)' : '(none)';
                $newValue[$field] = $new ? '(image)' : '(none)';
            } else {
                if ($old == $new) {
                    continue;
                }
                $oldValue[$field] = $old;
                $newValue[$field] = $new;
            }
            $row->{$field} = $new;
        }
        $row->updated_at = Carbon::now('UTC');
        $row->save();

        Audit::record(
            'ai_setting', self::SETTINGS_AUDIT_ID, 'updated', $user->id,
            details: 'AI Assistant settings',
            oldValue: $oldValue ?: null,
            newValue: $newValue ?: null,
        );

        return response()->json($this->presentSettings());
    }

    /** A one-line round trip to the provider, so the owner can prove the key works. */
    public function testConnection(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::ADMIN_MODULE, 'full');

        $interaction = new AiInteraction([
            'company_id' => $user->company_id,
            'user_id' => $user->id,
            'feature' => AiInteraction::FEATURE_CONNECTION_TEST,
            'model' => AiSetting::current()->model ?: AiSetting::DEFAULT_MODEL,
            'created_at' => Carbon::now('UTC'),
        ]);
        try {
            $result = AiClient::complete(
                'You are checking a connection. Reply with the JSON you were asked for.',
                'Reply with {"ok": true, "greeting": "<one short friendly sentence>"}.',
                ['type' => 'object', 'additionalProperties' => false, 'required' => ['ok', 'greeting'],
                    'properties' => ['ok' => ['type' => 'boolean'], 'greeting' => ['type' => 'string']]],
                256,
            );
        } catch (AiNotConfiguredException $e) {
            throw new ApiException(422, $e->getMessage());
        } catch (AiException $e) {
            $interaction->status = AiInteraction::STATUS_ERROR;
            $interaction->error = $e->getMessage();
            $interaction->save();
            throw new ApiException(502, $e->getMessage());
        }
        $interaction->model = $result->model;
        $interaction->input_tokens = $result->inputTokens;
        $interaction->output_tokens = $result->outputTokens;
        $interaction->status = $result->refused ? AiInteraction::STATUS_REFUSED : AiInteraction::STATUS_OK;
        $interaction->response = $result->data;
        $interaction->save();

        Audit::record('ai_setting', self::SETTINGS_AUDIT_ID, 'connection_tested', $user->id,
            details: "model={$result->model} tokens={$result->inputTokens}/{$result->outputTokens}");

        return response()->json([
            'ok' => ! $result->refused,
            'model' => $result->model,
            'greeting' => $result->data['greeting'] ?? null,
            'input_tokens' => $result->inputTokens,
            'output_tokens' => $result->outputTokens,
        ]);
    }

    /** Usage for the signed-in company: calls and tokens this month and all time (decision 12.2's evidence). */
    public function usage(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::ADMIN_MODULE, 'view');

        $base = AiInteraction::where('company_id', $user->company_id);
        $monthStart = Carbon::now('Asia/Singapore')->startOfMonth()->utc();
        $sum = fn ($q) => [
            'calls' => (clone $q)->count(),
            'ok' => (clone $q)->where('status', AiInteraction::STATUS_OK)->count(),
            'refused' => (clone $q)->where('status', AiInteraction::STATUS_REFUSED)->count(),
            'errors' => (clone $q)->where('status', AiInteraction::STATUS_ERROR)->count(),
            'input_tokens' => (int) (clone $q)->sum('input_tokens'),
            'output_tokens' => (int) (clone $q)->sum('output_tokens'),
        ];

        $recent = (clone $base)->orderByDesc('created_at')->limit(20)->get();
        $names = User::whereIn('id', $recent->pluck('user_id')->filter()->unique())->pluck('full_name', 'id');

        return response()->json([
            'this_month' => $sum((clone $base)->where('created_at', '>=', $monthStart)),
            'all_time' => $sum(clone $base),
            'recent' => $recent->map(fn (AiInteraction $i) => [
                'id' => $i->id,
                'created_at' => optional($i->created_at)->toJSON(),
                'user_name' => $names->get($i->user_id, ''),
                'feature' => $i->feature,
                'entity_type' => $i->entity_type,
                'entity_id' => $i->entity_id,
                'model' => $i->model,
                'status' => $i->status,
                'error' => $i->error,
                'input_tokens' => $i->input_tokens,
                'output_tokens' => $i->output_tokens,
            ])->values(),
        ]);
    }

    // ---- Chat ---------------------------------------------------------

    /** The assistant's name and face, for the chat panel. Doubles as the panel's licence probe: 403 hides it. */
    public function persona(Request $request)
    {
        $user = Authenticate::user($request);
        $this->requireLicensed($user);
        $row = AiSetting::current();

        return response()->json(['name' => $row->assistantName(), 'avatar' => $row->assistant_avatar]);
    }

    public function chat(Request $request)
    {
        $user = Authenticate::user($request);
        $this->requireLicensed($user);

        $data = $request->validate([
            'messages' => 'required|array|min:1|max:'.(AiChat::MAX_HISTORY * 2),
            'messages.*.role' => 'required|string',
            'messages.*.content' => 'required|string',
            'context' => 'sometimes|nullable|array',
            'context.type' => 'sometimes|nullable|string|max:30',
            'context.id' => 'sometimes|nullable|string|max:40',
        ]);

        try {
            $reply = AiChat::reply($user, $data['messages'], $data['context'] ?? null);
        } catch (AiValidationException $e) {
            throw new ApiException(422, $e->getMessage());
        } catch (AiNotConfiguredException $e) {
            throw new ApiException(422, $e->getMessage());
        } catch (AiException $e) {
            throw new ApiException(502, $e->getMessage());
        }

        return response()->json([
            'answer' => $reply['answer'],
            'refused' => $reply['refused'],
            'tools_used' => $reply['tools_used'],
            'model' => $reply['interaction']->model,
            'input_tokens' => $reply['interaction']->input_tokens,
            'output_tokens' => $reply['interaction']->output_tokens,
            'interaction_id' => $reply['interaction']->id,
        ]);
    }

    // ---- Incident triage ------------------------------------------

    public function triageIncident(Request $request, string $incident)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, 'service_operations', 'edit');
        $this->requireLicensed($user);

        $inc = $this->incidentOrFail($user->company_id, $incident);

        try {
            $interaction = IncidentTriage::suggest($inc, $user);
        } catch (AiNotConfiguredException $e) {
            throw new ApiException(422, $e->getMessage());
        } catch (AiException $e) {
            throw new ApiException(502, $e->getMessage());
        }

        return response()->json($this->presentTriage($interaction));
    }

    public function latestTriage(Request $request, string $incident)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, 'service_operations', 'view');
        $this->requireLicensed($user);

        $inc = $this->incidentOrFail($user->company_id, $incident);
        $latest = IncidentTriage::latest($inc);

        return response()->json($latest ? $this->presentTriage($latest) : null);
    }

    // ---- helpers ----------------------------------------------------

    /**
     * Decision 12.4: the AI Assistant is a paid add-on, so the module
     * key is a licence check that applies to everyone -- the owner
     * included, whom Authority::requireModuleAccess() otherwise lets
     * through a disabled module.
     */
    private function requireLicensed(User $user): void
    {
        Authority::requireModuleAccess($user, self::MODULE, 'view');
        if (! Authority::isModuleEnabled($user->company_id, self::MODULE)) {
            throw new ApiException(403, 'The AI Assistant module is not enabled for this company. It is a paid add-on -- enable it under Maintenance -> Module Control once licensed.');
        }
    }

    private function incidentOrFail(string $companyId, string $incidentId): Incident
    {
        $inc = Incident::where('company_id', $companyId)->find($incidentId);
        if (! $inc) {
            throw new ApiException(404, 'Incident not found');
        }

        return $inc;
    }

    private function presentSettings(): array
    {
        $row = AiSetting::current();

        return [
            'model' => $row->model ?: AiSetting::DEFAULT_MODEL,
            'redact_personal_data' => (bool) $row->redact_personal_data,
            'assistant_name' => $row->assistantName(),
            'assistant_avatar' => $row->assistant_avatar,
            'api_key_set' => $row->api_key_set,
            'api_key_from_env' => $row->api_key_from_env,
            'updated_at' => optional($row->updated_at)->toJSON(),
        ];
    }

    private function presentTriage(AiInteraction $i): array
    {
        return [
            'id' => $i->id,
            'incident_id' => $i->entity_id,
            'created_at' => optional($i->created_at)->toJSON(),
            'model' => $i->model,
            'status' => $i->status,
            'error' => $i->error,
            'input_tokens' => $i->input_tokens,
            'output_tokens' => $i->output_tokens,
            'suggestion' => $i->status === AiInteraction::STATUS_OK ? $i->response : null,
        ];
    }
}
