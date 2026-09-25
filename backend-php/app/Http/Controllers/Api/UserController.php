<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\AuditLogEntry;
use App\Models\Company;
use App\Models\Group;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Models\UserPasswordHistory;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Staff Master -- the list of staff/user accounts, their role (for the
 * named-responsibility business rules) and their Group (for Group
 * Authority / general module security). Mirrors
 * backend/app/routers/users.py -- see App\Models\User for the
 * two-axis RBAC design rationale.
 *
 * Multi-company: a staff member holds a Group **per company** they
 * work in, stored on UserCompanyAccess. `group_id` on these endpoints
 * always means "their Group in the company you are currently working
 * in"; the per-company view/edit lives on /company-access.
 *
 * NOT yet converted from the Python router (tracked in
 * docs/php-conversion-plan.md): CSV/Excel export.
 */
class UserController extends Controller
{
    use SendsExports;

    /** @var array<int, string> */
    private const EXPORT_FIELDS = ['username', 'full_name', 'email', 'role', 'group_name', 'is_active'];

    /** Last N passwords a user may not reuse (Staff Master, 2026-09-25). */
    private const PASSWORD_HISTORY_DEPTH = 5;

    private const MODULE = 'core_administration';

    private const MAX_PHOTO_CHARS = 400_000; // ~300 KB of base64

    private function userOrFail(string $userId): User
    {
        $user = User::find($userId);
        if (! $user) {
            throw new ApiException(404, 'User not found');
        }

        return $user;
    }

    private function accessRow(string $userId, string $companyId): ?UserCompanyAccess
    {
        return UserCompanyAccess::where('user_id', $userId)->where('company_id', $companyId)->first();
    }

    /** A Group only means something inside its own company, so refuse to assign one belonging to a different entity. */
    private function validateGroupForCompany(?string $groupId, string $companyId): void
    {
        if ($groupId === null) {
            return;
        }
        $group = Group::find($groupId);
        if (! $group) {
            throw new ApiException(400, 'Unknown group_id');
        }
        if ($group->company_id !== $companyId) {
            throw new ApiException(400, 'That Group belongs to a different company.');
        }
    }

    /** UserOut with `group_id` resolved to this user's Group in the given company (Group is per company). */
    private function present(User $user, string $companyId): array
    {
        $access = $this->accessRow($user->id, $companyId);

        return [
            'id' => $user->id,
            'username' => $user->username,
            'full_name' => $user->full_name,
            'email' => $user->email,
            'role' => $user->role,
            'group_id' => $access?->group_id,
            'photo' => $user->photo,
            'phone' => $user->phone,
            'must_change_password' => $user->must_change_password,
            'force_password_change_on_login' => $user->force_password_change_on_login,
            'is_active' => $user->is_active,
            'created_at' => $user->created_at,
            // PDPA self-declaration for the AI Assistant (2026-09-15) --
            // read-only here; see User::$casts's docblock for why.
            'ai_data_consent_at' => optional($user->ai_data_consent_at)->toJSON(),
        ];
    }

    /**
     * Deliberately NOT gated by core_administration: this basic staff
     * directory (name/role) is used across other modules too, e.g. an
     * "assign to" picker -- any signed-in user may read it. Staff
     * Master's mutating actions and single-record lookup ARE gated.
     */
    public function index(Request $request)
    {
        $user = Authenticate::user($request);

        return $this->filtered($user->company_id, $request)
            ->map(fn ($u) => $this->present($u, $user->company_id))->values();
    }

    /**
     * The list the screen shows, honouring its filter -- shared with
     * the exports so an Export button always returns what is on
     * screen.
     *
     * @return Collection<int, User>
     */
    private function filtered(string $companyId, Request $request)
    {
        $query = User::where('company_id', $companyId);
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return $query->orderBy('full_name')->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(string $companyId, Request $request): array
    {
        $users = $this->filtered($companyId, $request);
        $groupIds = UserCompanyAccess::whereIn('user_id', $users->pluck('id'))
            ->where('company_id', $companyId)->pluck('group_id')->filter()->unique();
        $groupNames = $groupIds->isEmpty() ? collect() : Group::whereIn('id', $groupIds)->pluck('name', 'id');

        return $users->map(function (User $u) use ($companyId, $groupNames) {
            $groupId = $this->accessRow($u->id, $companyId)?->group_id;

            return [
                'username' => $u->username,
                'full_name' => $u->full_name,
                'email' => $u->email,
                'role' => $u->role,
                'group_name' => $groupId === null ? '' : ($groupNames[$groupId] ?? ''),
                'is_active' => $u->is_active,
            ];
        })->all();
    }

    public function exportCsv(Request $request)
    {
        $user = Authenticate::user($request);

        return $this->csvResponse(self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request), 'users.csv');
    }

    public function exportExcel(Request $request)
    {
        $user = Authenticate::user($request);

        return $this->xlsxResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request), 'Users', 'users.xlsx'
        );
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $data = $request->validate([
            'username' => 'required|string',
            'email' => 'required|email',
            'password' => 'required|string',
            'full_name' => 'required|string',
            'role' => 'required|in:owner,service_lead,sales_manager,support_engineer,finance',
            'group_id' => 'sometimes|nullable|uuid',
            'phone' => ['sometimes', 'nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
        ]);

        $this->validateUsername($data['username']);

        if (User::where('email', $data['email'])->exists()) {
            throw new ApiException(409, 'A user with this email already exists.');
        }
        $groupId = $data['group_id'] ?? null;
        $this->validateGroupForCompany($groupId, $user->company_id);
        try {
            PasswordPolicy::validateComplexity($data['password']);
        } catch (InvalidArgumentException $e) {
            throw new ApiException(422, $e->getMessage());
        }

        $hashedPassword = PasswordPolicy::hash($data['password']);
        $newUser = User::create([
            'company_id' => $user->company_id,
            'username' => $data['username'],
            'email' => $data['email'],
            'hashed_password' => $hashedPassword,
            'full_name' => $data['full_name'],
            'role' => $data['role'],
            'phone' => $data['phone'] ?? null,
            // Confirmed 2026-09-12: every new staff account must set
            // its own password the first time it signs in.
            'must_change_password' => true,
        ]);
        $this->recordPasswordHistory($newUser->id, $hashedPassword);

        // New staff start with access to the company they were created
        // in, with the Group chosen for them there.
        UserCompanyAccess::create(['user_id' => $newUser->id, 'company_id' => $user->company_id, 'group_id' => $groupId]);

        Audit::record(
            'user', $newUser->id, 'created', $user->id,
            details: "username={$data['username']}, email={$data['email']}, role={$data['role']}",
            newValue: [
                'username' => $data['username'],
                'email' => $data['email'],
                'full_name' => $data['full_name'],
                'role' => $data['role'],
                'group_id' => $groupId,
            ],
        );

        return response()->json($this->present($newUser, $user->company_id));
    }

    public function show(Request $request, string $userId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return response()->json($this->present($this->userOrFail($userId), $user->company_id));
    }

    /** Which companies this staff member may work in, and their Group in each (Group is per company). */
    public function companyAccess(Request $request, string $userId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $this->userOrFail($userId);
        $rows = UserCompanyAccess::where('user_id', $userId)->get();

        return $rows->map(function ($row) {
            $company = Company::find($row->company_id);
            $group = $row->group_id ? Group::find($row->group_id) : null;

            return [
                'company_id' => $row->company_id,
                'company_name' => $company?->name ?? '(unknown)',
                'group_id' => $row->group_id,
                'group_name' => $group?->name,
            ];
        })->values();
    }

    /** Replace the set of companies this staff member may work in, and their Group in each. Omitting a company revokes access to it. */
    public function setCompanyAccess(Request $request, string $userId)
    {
        $actingUser = Authenticate::user($request);
        Authority::requireModuleAccess($actingUser, self::MODULE, 'full');

        $target = $this->userOrFail($userId);
        $data = $request->validate([
            'access' => 'required|array',
            'access.*.company_id' => 'required|uuid',
            'access.*.group_id' => 'sometimes|nullable|uuid',
        ]);

        $requested = [];
        foreach ($data['access'] as $entry) {
            $requested[$entry['company_id']] = $entry['group_id'] ?? null;
        }
        foreach ($requested as $companyId => $groupId) {
            if (! Company::find($companyId)) {
                throw new ApiException(400, 'Unknown company_id');
            }
            $this->validateGroupForCompany($groupId, $companyId);
        }

        $existing = UserCompanyAccess::where('user_id', $userId)->get()->keyBy('company_id');

        $label = function (?string $groupId) {
            $group = $groupId ? Group::find($groupId) : null;

            return $group?->name ?? '(no group)';
        };

        $oldValue = [];
        $newValue = [];

        // Revoke companies no longer listed -- but never strand someone
        // in a company they are currently working in.
        foreach ($existing as $companyId => $row) {
            if (array_key_exists($companyId, $requested)) {
                continue;
            }
            if ($companyId === $target->company_id) {
                throw new ApiException(400, 'Cannot revoke access to the company this staff member is currently working in.');
            }
            $company = Company::find($companyId);
            $key = $company?->name ?? $companyId;
            $oldValue[$key] = $label($row->group_id);
            $newValue[$key] = '(no access)';
            $row->delete();
        }

        // Add or re-group the rest.
        foreach ($requested as $companyId => $groupId) {
            $company = Company::find($companyId);
            $key = $company?->name ?? $companyId;
            $row = $existing->get($companyId);
            if ($row === null) {
                $oldValue[$key] = '(no access)';
                $newValue[$key] = $label($groupId);
                UserCompanyAccess::create(['user_id' => $userId, 'company_id' => $companyId, 'group_id' => $groupId]);
            } elseif ($row->group_id !== $groupId) {
                $oldValue[$key] = $label($row->group_id);
                $newValue[$key] = $label($groupId);
                $row->group_id = $groupId;
                $row->save();
            }
        }

        Audit::record('user', $target->id, 'company_access_updated', $actingUser->id, oldValue: $oldValue ?: null, newValue: $newValue ?: null);

        return $this->companyAccess($request, $userId);
    }

    /** Recent Staff Master activity for this account -- the audit trail CLAUDE.md requires for account-affecting actions. */
    public function auditLog(Request $request, string $userId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $this->userOrFail($userId);

        return AuditLogEntry::where('entity_type', 'user')
            ->where('entity_id', $userId)
            ->orderByDesc('at')
            ->limit(50)
            ->get();
    }

    public function update(Request $request, string $userId)
    {
        $actingUser = Authenticate::user($request);
        Authority::requireModuleAccess($actingUser, self::MODULE, 'full');

        $target = $this->userOrFail($userId);

        // Username is set once at account creation and reset only the
        // way a password is (by an admin, not a self-edit) -- see
        // validateUsername()'s docblock.
        if ($request->has('username')) {
            throw new ApiException(400, 'Username cannot be changed after account creation.');
        }

        $fields = $request->validate([
            'full_name' => 'sometimes|string',
            'email' => 'sometimes|email',
            'role' => 'sometimes|in:owner,service_lead,sales_manager,support_engineer,finance',
            'photo' => 'sometimes|nullable|string',
            'group_id' => 'sometimes|nullable|uuid',
            'force_password_change_on_login' => 'sometimes|boolean',
            // E.164: + then country code then subscriber number, digits
            // only (e.g. +6591234567) -- what WhatsAppSender expects.
            'phone' => ['sometimes', 'nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
        ]);

        $oldValue = [];
        $newValue = [];

        foreach (['full_name', 'role', 'phone', 'force_password_change_on_login'] as $field) {
            if (! array_key_exists($field, $fields) || $target->{$field} === $fields[$field]) {
                continue;
            }
            $oldValue[$field] = $target->{$field};
            $newValue[$field] = $fields[$field];
            $target->{$field} = $fields[$field];
        }

        if (array_key_exists('email', $fields) && $target->email !== $fields['email']) {
            if (User::where('email', $fields['email'])->where('id', '!=', $target->id)->exists()) {
                throw new ApiException(409, 'A user with this email already exists.');
            }
            $oldValue['email'] = $target->email;
            $newValue['email'] = $fields['email'];
            $target->email = $fields['email'];
        }

        if (array_key_exists('photo', $fields)) {
            $this->validatePhoto($fields['photo']);
            $oldPhoto = $target->photo;
            $newPhoto = $fields['photo'];
            if ($oldPhoto !== $newPhoto) {
                // Never dump base64 image data into the audit trail --
                // record that it changed, not the pixels.
                $oldValue['photo'] = $oldPhoto ? '(image set)' : '(none)';
                $newValue['photo'] = $newPhoto ? '(image set)' : '(none)';
            }
            $target->photo = $newPhoto;
        }

        if (array_key_exists('group_id', $fields)) {
            // "Their Group in the company I am currently working in" --
            // Group is per company, so this writes to the access row,
            // not the user.
            $newGroupId = $fields['group_id'];
            $this->validateGroupForCompany($newGroupId, $actingUser->company_id);
            $access = $this->accessRow($target->id, $actingUser->company_id);
            if ($access === null) {
                UserCompanyAccess::create(['user_id' => $target->id, 'company_id' => $actingUser->company_id, 'group_id' => $newGroupId]);
                $oldValue['group_id'] = null;
                $newValue['group_id'] = $newGroupId;
            } elseif ($access->group_id !== $newGroupId) {
                $oldValue['group_id'] = $access->group_id;
                $newValue['group_id'] = $newGroupId;
                $access->group_id = $newGroupId;
                $access->save();
            }
        }

        Audit::record('user', $target->id, 'updated', $actingUser->id, oldValue: $oldValue ?: null, newValue: $newValue ?: null);
        $target->save();

        return response()->json($this->present($target->fresh(), $actingUser->company_id));
    }

    public function deactivate(Request $request, string $userId)
    {
        $actingUser = Authenticate::user($request);
        Authority::requireModuleAccess($actingUser, self::MODULE, 'full');

        $target = $this->userOrFail($userId);
        if ($target->id === $actingUser->id) {
            throw new ApiException(400, 'You cannot deactivate your own account.');
        }
        $target->is_active = false;
        Audit::record('user', $target->id, 'deactivated', $actingUser->id, oldValue: ['is_active' => true], newValue: ['is_active' => false]);
        $target->save();

        return response()->json($this->present($target->fresh(), $actingUser->company_id));
    }

    public function reactivate(Request $request, string $userId)
    {
        $actingUser = Authenticate::user($request);
        Authority::requireModuleAccess($actingUser, self::MODULE, 'full');

        $target = $this->userOrFail($userId);
        $target->is_active = true;
        Audit::record('user', $target->id, 'reactivated', $actingUser->id, oldValue: ['is_active' => false], newValue: ['is_active' => true]);
        $target->save();

        return response()->json($this->present($target->fresh(), $actingUser->company_id));
    }

    public function resetPassword(Request $request, string $userId)
    {
        $actingUser = Authenticate::user($request);
        Authority::requireModuleAccess($actingUser, self::MODULE, 'full');

        $target = $this->userOrFail($userId);
        $data = $request->validate([
            'new_password' => 'required|string',
            'force_password_change_on_login' => 'sometimes|boolean',
        ]);
        try {
            PasswordPolicy::validateComplexity($data['new_password']);
        } catch (InvalidArgumentException $e) {
            throw new ApiException(422, $e->getMessage());
        }
        if ($this->wasPasswordUsedRecently($target->id, $data['new_password'])) {
            throw new ApiException(422, 'That password was used recently -- choose one of the last '.self::PASSWORD_HISTORY_DEPTH." you haven't.");
        }

        $hashedPassword = PasswordPolicy::hash($data['new_password']);
        $target->hashed_password = $hashedPassword;
        // An admin-issued reset is a temporary password -- force the
        // user to set their own on next sign-in, same as a brand-new account.
        $target->must_change_password = true;
        if (array_key_exists('force_password_change_on_login', $data)) {
            $target->force_password_change_on_login = $data['force_password_change_on_login'];
        }
        $this->recordPasswordHistory($target->id, $hashedPassword);
        Audit::record('user', $target->id, 'password_reset', $actingUser->id);
        $target->save();

        return response()->json($this->present($target->fresh(), $actingUser->company_id));
    }

    private function validatePhoto(?string $photo): void
    {
        if ($photo === null) {
            return;
        }
        if (! Str::startsWith($photo, 'data:image/')) {
            throw new ApiException(400, "Photo must be an image data URI (e.g. 'data:image/png;base64,...').");
        }
        if (strlen($photo) > self::MAX_PHOTO_CHARS) {
            throw new ApiException(400, 'Photo is too large -- please use an image under ~300 KB.');
        }
    }

    /**
     * Separate from email (confirmed with Dennis, 2026-09-25) and
     * immutable after account creation -- like a password, it is reset
     * by an admin rather than edited, though no reset-username action
     * exists yet since nobody has asked to change one in practice.
     */
    private function validateUsername(string $username): void
    {
        if (! preg_match('/^[a-zA-Z0-9_-]{3,20}$/', $username)) {
            throw new ApiException(400, 'Username must be 3-20 characters: letters, numbers, underscore or hyphen only.');
        }
        if (User::where('username', $username)->exists()) {
            throw new ApiException(409, 'That username is already taken.');
        }
    }

    /** Last PASSWORD_HISTORY_DEPTH passwords may not be reused, on either an admin reset or a self-service change. */
    private function wasPasswordUsedRecently(string $userId, string $plainPassword): bool
    {
        return UserPasswordHistory::where('user_id', $userId)
            ->orderByDesc('set_at')
            ->limit(self::PASSWORD_HISTORY_DEPTH)
            ->get()
            ->contains(fn (UserPasswordHistory $entry) => PasswordPolicy::verify($plainPassword, $entry->hashed_password));
    }

    private function recordPasswordHistory(string $userId, string $hashedPassword): void
    {
        UserPasswordHistory::create(['user_id' => $userId, 'hashed_password' => $hashedPassword, 'set_at' => Carbon::now('UTC')]);
    }
}
