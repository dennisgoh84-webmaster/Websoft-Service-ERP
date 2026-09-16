<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticatePortal;
use App\Models\AiSetting;
use App\Models\PortalUser;
use App\Services\Ai\AiBudgetExceededException;
use App\Services\Ai\AiChat;
use App\Services\Ai\AiException;
use App\Services\Ai\AiNotConfiguredException;
use App\Services\Ai\AiPortalChat;
use App\Services\Ai\AiValidationException;
use App\Services\Authority;
use Illuminate\Http\Request;

/**
 * The Customer Helpdesk Portal's AI Assistant chat (slice 3,
 * docs/planned-work.md #12 Tier 2 item 6). Deliberately its own
 * controller behind `auth.portal` rather than a portal branch inside
 * AiAssistantController -- PORTAL-003's separation of auth realms
 * applies here too, and the two never share a token or a code path.
 */
class PortalAiController extends Controller
{
    private function portalUser(Request $request): PortalUser
    {
        return AuthenticatePortal::portalUser($request);
    }

    /**
     * The `ai_assistant` module key is the company's licence for AI
     * features at all (decision 12.4); the portal has no group/role
     * concept to check beyond that.
     */
    private function requireLicensed(PortalUser $portalUser): void
    {
        if (! Authority::isModuleEnabled($portalUser->company_id, 'ai_assistant')) {
            throw new ApiException(403, 'The AI Assistant is not available on this portal.');
        }
    }

    public function persona(Request $request)
    {
        $portalUser = $this->portalUser($request);
        $this->requireLicensed($portalUser);
        $row = AiSetting::current();

        return response()->json(['name' => $row->assistantName(), 'avatar' => $row->assistant_avatar]);
    }

    public function chat(Request $request)
    {
        $portalUser = $this->portalUser($request);
        $this->requireLicensed($portalUser);

        $data = $request->validate([
            'messages' => 'required|array|min:1|max:'.(AiChat::MAX_HISTORY * 2),
            'messages.*.role' => 'required|string',
            'messages.*.content' => 'required|string',
            'context' => 'sometimes|nullable|array',
            'context.type' => 'sometimes|nullable|string|max:30',
            'context.id' => 'sometimes|nullable|string|max:40',
        ]);

        try {
            $reply = AiPortalChat::reply($portalUser, $data['messages'], $data['context'] ?? null);
        } catch (AiValidationException $e) {
            throw new ApiException(422, $e->getMessage());
        } catch (AiBudgetExceededException $e) {
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
        ]);
    }
}
