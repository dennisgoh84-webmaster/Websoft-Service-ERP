<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\CompanyIndividual;
use App\Models\InboxEmail;
use App\Services\Authority;
use App\Services\EmailInbox;
use App\Services\IncidentService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Email Inbox -- the helpdesk mailbox read by the server, for staff to
 * Log as Incident, Convert to Job Order or Dismiss. Under Service
 * Operations, like Incidents: VIEW to read, EDIT to act.
 */
class EmailInboxController extends Controller
{
    private const MODULE = 'service_operations';

    /** @return array<string, mixed> */
    private function present(InboxEmail $e, ?string $companyId = null): array
    {
        $matched = null;
        if ($e->status === InboxEmail::STATUS_NEW && $companyId !== null) {
            $customerId = IncidentService::tryMatchCustomerByEmail($companyId, $e->from_email);
            $matched = $customerId ? CompanyIndividual::find($customerId)?->name : null;
        }

        return [
            'id' => $e->id,
            'from_name' => $e->from_name,
            'from_email' => $e->from_email,
            'subject' => $e->subject,
            'received_at' => $e->received_at->toIso8601String(),
            'body_text' => $e->body_text,
            'attachment_names' => $e->attachment_names ?? [],
            'status' => $e->status,
            'matched_company_individual' => $matched,
            'incident_id' => $e->incident_id,
            'incident_number' => $e->incident?->incident_number,
            'job_order_id' => $e->job_order_id,
            'job_order_number' => $e->jobOrder?->job_order_number,
            'handled_by_name' => $e->handledBy?->full_name,
            'handled_at' => optional($e->handled_at)->toIso8601String(),
            'dismiss_reason' => $e->dismiss_reason,
        ];
    }

    /** @return array<string, mixed> */
    private function mailboxState(?array $check = null): array
    {
        $box = EmailInbox::mailbox();

        return [
            'configured' => $box !== null && $box->isImapConfigured(),
            'address' => $box?->imap_username,
            'last_checked_at' => $box?->imap_last_checked_at ? Carbon::parse($box->imap_last_checked_at)->toIso8601String() : null,
            'last_error' => $box?->imap_last_error,
            'added' => $check['added'] ?? 0,
        ];
    }

    private function emailOrFail(string $id): InboxEmail
    {
        $e = Str::isUuid($id) ? InboxEmail::find($id) : null;
        if ($e === null) {
            throw new ApiException(404, 'Email not found');
        }

        return $e;
    }

    /** The list; opening it checks the mailbox if the last check was over a minute ago. */
    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');
        $status = $request->query('status', InboxEmail::STATUS_NEW);
        if (! in_array($status, InboxEmail::STATUSES, true)) {
            throw new ApiException(422, 'Unknown status');
        }
        $check = EmailInbox::check();

        $emails = InboxEmail::with(['incident', 'jobOrder', 'handledBy'])
            ->where('status', $status)
            // Handled emails show only to the company that handled them.
            ->when($status !== InboxEmail::STATUS_NEW, fn ($q) => $q->where('company_id', $user->company_id))
            ->orderByDesc('received_at')->limit(200)->get();

        return response()->json([
            'mailbox' => $this->mailboxState($check),
            'counts' => ['new' => InboxEmail::where('status', InboxEmail::STATUS_NEW)->count()],
            'emails' => $emails->map(fn ($e) => $this->present($e, $user->company_id))->values(),
        ]);
    }

    /** Check now. */
    public function check(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return response()->json(['mailbox' => $this->mailboxState(EmailInbox::check(force: true))]);
    }

    public function logIncident(Request $request, string $email)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        return response()->json($this->present(EmailInbox::logAsIncident($this->emailOrFail($email), $user)));
    }

    public function convertToJobOrder(Request $request, string $email)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');
        $r = EmailInbox::convertToJobOrder($this->emailOrFail($email), $user);

        return response()->json($this->present($r['email']) + ['fallback_reason' => $r['fallback_reason']]);
    }

    public function dismiss(Request $request, string $email)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');
        $reason = $request->validate(['reason' => ['required', 'string', 'max:2000']])['reason'];

        return response()->json($this->present(EmailInbox::dismiss($this->emailOrFail($email), $user, $reason)));
    }

    public function restore(Request $request, string $email)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        return response()->json($this->present(EmailInbox::restore($this->emailOrFail($email), $user), $user->company_id));
    }
}
