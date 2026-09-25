<?php

namespace App\Services;

use App\Exceptions\ApiException;
use App\Models\CompanyModule;
use App\Models\GroupModuleAuthority;
use App\Models\User;
use App\Models\UserCompanyAccess;

/**
 * Group Authority enforcement -- the per-module security layer,
 * combined with Module Control (per-company enable/disable licensing).
 * Mirrors backend/app/services/authority.py exactly, including the
 * owner-role bypass of both checks -- see that file's docstring for
 * the full design rationale.
 */
class Authority
{
    /**
     * The Group this user holds in a given company (defaults to the
     * one they are currently working in). The assignment lives on
     * UserCompanyAccess, because a staff member has a Group *per
     * company* -- see App\Models\User.
     */
    public static function getUserGroupId(User $user, ?string $companyId = null): ?string
    {
        $access = UserCompanyAccess::query()
            ->where('user_id', $user->id)
            ->where('company_id', $companyId ?? $user->company_id)
            ->first();

        return $access?->group_id;
    }

    public static function getAccessLevel(User $user, string $moduleKey): string
    {
        if ($user->role === User::ROLE_OWNER) {
            return GroupModuleAuthority::FULL;
        }
        $groupId = self::getUserGroupId($user);
        if ($groupId === null) {
            return GroupModuleAuthority::NONE;
        }
        $row = GroupModuleAuthority::query()
            ->where('group_id', $groupId)
            ->where('module_key', $moduleKey)
            ->first();

        return $row?->access_level ?? GroupModuleAuthority::NONE;
    }

    public static function hasAccess(User $user, string $moduleKey, string $minLevel): bool
    {
        return GroupModuleAuthority::LEVEL_ORDER[self::getAccessLevel($user, $moduleKey)]
            >= GroupModuleAuthority::LEVEL_ORDER[$minLevel];
    }

    /**
     * Module Control: has this company switched `moduleKey` on? A
     * missing CompanyModule row counts as not enabled -- fail closed,
     * not open.
     */
    public static function isModuleEnabled(string $companyId, string $moduleKey): bool
    {
        $cm = CompanyModule::query()
            ->where('company_id', $companyId)
            ->where('module_key', $moduleKey)
            ->first();

        return (bool) ($cm && $cm->enabled);
    }

    /**
     * Checks Group Authority AND Module Control together, throwing a
     * 403 ApiException if either fails. The owner bypasses both --
     * never locked out of their own system by a misconfigured group or
     * an accidentally-disabled module. Called from
     * App\Http\Middleware\RequireModuleAccess.
     */
    public static function requireModuleAccess(User $user, string $moduleKey, string $minLevel): void
    {
        if (! self::hasAccess($user, $moduleKey, $minLevel)) {
            throw new ApiException(403, "Your group does not have {$minLevel} access to the '{$moduleKey}' module.");
        }
        if ($user->role !== User::ROLE_OWNER && ! self::isModuleEnabled($user->company_id, $moduleKey)) {
            throw new ApiException(403, "The '{$moduleKey}' module is not enabled for your company. Ask an owner/admin to enable it under Module Control.");
        }
    }

    /**
     * requireModuleAccess() for an Internal Company other than the one
     * the user is signed in to (Data Migration imports into a chosen
     * Internal Company): the user must be able to open that company,
     * hold the level there through their Group in THAT company, and
     * the module must be enabled for it. The owner bypasses the Group
     * and Module Control checks, as everywhere.
     */
    public static function requireModuleAccessIn(User $user, string $companyId, string $moduleKey, string $minLevel): void
    {
        if ($companyId === $user->company_id) {
            self::requireModuleAccess($user, $moduleKey, $minLevel);

            return;
        }
        if ($user->role === User::ROLE_OWNER) {
            return;
        }
        $groupId = self::getUserGroupId($user, $companyId);
        if ($groupId === null) {
            throw new ApiException(403, 'You do not have access to that Internal Company.');
        }
        $level = GroupModuleAuthority::query()
            ->where('group_id', $groupId)->where('module_key', $moduleKey)
            ->value('access_level') ?? GroupModuleAuthority::NONE;
        if (GroupModuleAuthority::LEVEL_ORDER[$level] < GroupModuleAuthority::LEVEL_ORDER[$minLevel]) {
            throw new ApiException(403, "Your group in that Internal Company does not have {$minLevel} access to the '{$moduleKey}' module.");
        }
        if (! self::isModuleEnabled($companyId, $moduleKey)) {
            throw new ApiException(403, "The '{$moduleKey}' module is not enabled for that Internal Company. Enable it under Module Control.");
        }
    }
}
