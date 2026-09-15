<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\SystemMailSetting;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Mailer;
use App\Services\MailerException;
use App\Services\MailerNotConfiguredException;
use Illuminate\Http\Request;

/**
 * Maintenance -> System Email: the two system-level mailboxes
 * (App\Models\SystemMailSetting). Global, like Announcements, and
 * gated the same way: core_administration, FULL to change anything.
 *
 * Save = live immediately, as on every other admin screen here. The
 * password is write-only: omitted from a PATCH it is left alone, sent
 * as null it is cleared, and it is never returned -- only the fact of
 * one being on file.
 */
class SystemMailController extends Controller
{
    private const MODULE = 'core_administration';

    /**
     * Fixed UUIDs standing in for the two rows in the audit trail
     * (AuditLogEntry::$entity_id is a UUID; these rows are keyed by
     * purpose) -- same device AnnouncementController uses for its
     * singleton, continuing its numbering.
     */
    private const AUDIT_IDS = [
        SystemMailSetting::PURPOSE_OTP => '00000000-0000-0000-0000-000000000002',
        SystemMailSetting::PURPOSE_HELPDESK => '00000000-0000-0000-0000-000000000003',
    ];

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return response()->json([
            'otp' => $this->present(SystemMailSetting::PURPOSE_OTP),
            'helpdesk' => $this->present(SystemMailSetting::PURPOSE_HELPDESK),
        ]);
    }

    public function update(Request $request, string $purpose)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');
        $this->purposeOrFail($purpose);

        $fields = $request->validate([
            'host' => 'sometimes|nullable|string|max:255',
            'port' => 'sometimes|integer|min:1|max:65535',
            'username' => 'sometimes|nullable|string|max:255',
            'password' => 'sometimes|nullable|string',
            'use_tls' => 'sometimes|boolean',
            'from_email' => 'sometimes|nullable|email|max:255',
            'from_name' => 'sometimes|nullable|string|max:255',
        ]);

        $row = SystemMailSetting::firstOrNew(['purpose' => $purpose]);
        $oldValue = [];
        $newValue = [];
        foreach ($fields as $field => $new) {
            $old = $row->{$field};
            if ($old == $new) {
                continue;
            }
            if ($field === 'password') {
                // Record THAT it changed, never the credential itself.
                $oldValue[$field] = $old ? '(set)' : '(none)';
                $newValue[$field] = $new ? '(set)' : '(none)';
            } else {
                $oldValue[$field] = $old;
                $newValue[$field] = $new;
            }
            $row->{$field} = $new;
        }
        $row->save();

        Audit::record(
            'system_mail_setting', self::AUDIT_IDS[$purpose], 'updated', $user->id,
            details: "{$purpose} mailbox",
            oldValue: $oldValue ?: null,
            newValue: $newValue ?: null,
        );

        return response()->json($this->present($purpose));
    }

    public function testEmail(Request $request, string $purpose)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');
        $this->purposeOrFail($purpose);

        $data = $request->validate(['to_email' => 'required|email']);
        $label = $purpose === SystemMailSetting::PURPOSE_HELPDESK ? 'Helpdesk' : 'sign-in / OTP';

        try {
            Mailer::sendSystemTest(
                $purpose,
                $data['to_email'],
                "Test email from the Websoft Service ERP {$label} mailbox",
                "This is a test message confirming the {$label} mailbox is working.\n\n"
                .($purpose === SystemMailSetting::PURPOSE_HELPDESK
                    ? "If you received this, acknowledgements for emails converted from Outlook will send correctly.\n"
                    : "If you received this, login codes, password resets and portal invites will send correctly.\n"),
            );
        } catch (MailerNotConfiguredException $e) {
            throw new ApiException(422, $e->getMessage());
        } catch (MailerException $e) {
            // 502: the settings were accepted, the mail server refused.
            throw new ApiException(502, $e->getMessage());
        }

        Audit::record('system_mail_setting', self::AUDIT_IDS[$purpose], 'smtp_test_sent', $user->id,
            details: "{$purpose} mailbox: test email sent to {$data['to_email']}");

        return response()->json(['sent' => true, 'to' => $data['to_email']]);
    }

    private function purposeOrFail(string $purpose): void
    {
        if (! in_array($purpose, SystemMailSetting::PURPOSES, true)) {
            throw new ApiException(404, 'No such mailbox');
        }
    }

    private function present(string $purpose): array
    {
        $row = SystemMailSetting::find($purpose);
        $isOtp = $purpose === SystemMailSetting::PURPOSE_OTP;

        return [
            'purpose' => $purpose,
            'host' => $row?->host,
            'port' => $row?->port ?? 587,
            'username' => $row?->username,
            'use_tls' => $row?->use_tls ?? true,
            'from_email' => $row?->from_email,
            'from_name' => $row?->from_name,
            'password_set' => (bool) $row?->password_set,
            'configured' => $isOtp ? Mailer::isConfigured() : Mailer::isHelpdeskConfigured(),
            // Which settings are live for this purpose right now:
            // 'database' (this row), 'env' (the otp fallback in .env,
            // shown so an operator knows the row is not yet in charge),
            // or 'none'.
            'source' => $isOtp ? Mailer::otpSource() : ($row?->isConfigured() ? 'database' : 'none'),
        ];
    }
}
