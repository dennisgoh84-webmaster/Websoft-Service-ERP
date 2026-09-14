<?php

namespace App\Services;

use App\Models\AuditLogEntry;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Central audit logging helper -- every module writes through here
 * rather than each inventing its own logging, per
 * docs/system-architecture.md's Audit logging section. This is the
 * data source for the Event Logs module. Mirrors
 * backend/app/services/audit.py exactly.
 *
 * Request-scoped context (who + which browser/device made the call) is
 * captured once per request by CaptureAuditRequestContext middleware
 * into a static holder, so callers don't need to thread the current
 * Request through every service call just to log an action.
 */
class Audit
{
    /** @var array{ip_address: ?string, user_agent: ?string, device_id: ?string} */
    private static array $requestContext = [
        'ip_address' => null,
        'user_agent' => null,
        'device_id' => null,
    ];

    public static function setRequestContext(?string $ipAddress, ?string $userAgent, ?string $deviceId): void
    {
        self::$requestContext = [
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'device_id' => $deviceId,
        ];
    }

    /**
     * `actorName`/`companyId` are only for a non-staff actor (the
     * Customer Helpdesk Portal); a staff `actorUserId` always overrides
     * both by looking the User up, same as the Python version.
     *
     * @param  array<string, mixed>|null  $oldValue
     * @param  array<string, mixed>|null  $newValue
     */
    public static function record(
        string $entityType,
        string $entityId,
        string $action,
        ?string $actorUserId,
        ?string $actorName = null,
        ?string $companyId = null,
        ?string $reason = null,
        ?string $details = null,
        ?array $oldValue = null,
        ?array $newValue = null,
    ): AuditLogEntry {
        if ($actorUserId !== null) {
            $actor = User::find($actorUserId);
            if ($actor !== null) {
                $actorName = $actor->full_name;
                // The company the actor was working in when this
                // happened, so Event Logs can show each company only
                // its own trail.
                $companyId = $actor->company_id;
            }
        }

        return AuditLogEntry::create([
            'company_id' => $companyId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'actor_user_id' => $actorUserId,
            'actor_name' => $actorName,
            'reason' => $reason,
            'details' => $details,
            'old_value' => self::toJson($oldValue),
            'new_value' => self::toJson($newValue),
            'ip_address' => self::$requestContext['ip_address'],
            'user_agent' => self::$requestContext['user_agent'],
            'device_id' => self::$requestContext['device_id'],
        ]);
    }

    public static function recordReportGenerated(?string $actorUserId, string $reportName, ?string $details = null): AuditLogEntry
    {
        return self::record(
            entityType: 'report',
            entityId: (string) Str::uuid(),
            action: 'report_generated',
            actorUserId: $actorUserId,
            details: $details ?? $reportName,
            newValue: ['report' => $reportName],
        );
    }

    /** @param array<string, mixed>|null $value */
    private static function toJson(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
