<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Mailer;
use App\Services\MailerException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Company Setup / multi-company. Mirrors backend/app/routers/companies.py
 * -- see that file's module docstring for the full design rationale.
 */
class CompanyController extends Controller
{
    private const MODULE = 'core_administration';

    private const MAX_LOGO_CHARS = 400_000; // ~300 KB of base64

    /**
     * Companies this user may switch to. The owner can reach every
     * company; everyone else is limited to their explicit
     * UserCompanyAccess rows, plus their current company so they can
     * never be stranded without one.
     *
     * @return array<int, string>
     */
    public static function accessibleCompanyIds(User $user): array
    {
        if ($user->role === User::ROLE_OWNER) {
            return Company::query()->pluck('id')->all();
        }
        $ids = UserCompanyAccess::where('user_id', $user->id)->pluck('company_id')->all();

        return array_values(array_unique([...$ids, $user->company_id]));
    }

    /** The Login page's logo/name -- deliberately the only unauthenticated endpoint here. */
    public function publicBranding()
    {
        $company = Company::where('is_active', true)->orderBy('created_at')->first();
        if (! $company) {
            return response()->json(['name' => 'Websoft Service ERP', 'logo' => null]);
        }

        return response()->json(['name' => $company->name, 'logo' => $company->logo]);
    }

    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        $ids = self::accessibleCompanyIds($user);

        return Company::whereIn('id', $ids)->where('is_active', true)->orderBy('name')->get();
    }

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::FULL);

        $data = $request->validate([
            'name' => 'required|string',
            'country' => 'sometimes|string',
            'currency' => 'sometimes|string',
            'timezone' => 'sometimes|string',
            'logo' => 'nullable|string',
        ]);
        self::validateLogo($data['logo'] ?? null);

        $company = Company::create([
            'name' => $data['name'],
            'country' => $data['country'] ?? 'Singapore',
            'currency' => $data['currency'] ?? 'SGD',
            'timezone' => $data['timezone'] ?? 'Asia/Singapore',
            'logo' => $data['logo'] ?? null,
        ]);

        // A new company starts with the same module catalog, all
        // disabled except the ones already built.
        $builtModuleKeys = [];
        foreach (ModuleCatalog::all() as $module) {
            if ($module->is_built) {
                $builtModuleKeys[] = $module->key;
            }
            CompanyModule::create([
                'company_id' => $company->id,
                'module_key' => $module->key,
                'enabled' => $module->is_built,
                'license_type' => CompanyModule::INCLUDED,
            ]);
        }

        // Groups are per company; bootstrap one admin group with full
        // access to the built modules.
        $adminGroup = Group::create([
            'company_id' => $company->id,
            'name' => 'Owner / Admin',
            'description' => 'Full access to every module in this company. Created with the company.',
        ]);
        foreach ($builtModuleKeys as $moduleKey) {
            GroupModuleAuthority::create([
                'group_id' => $adminGroup->id,
                'module_key' => $moduleKey,
                'access_level' => GroupModuleAuthority::FULL,
            ]);
        }

        // Whoever created it can work in it, as an admin there.
        UserCompanyAccess::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'group_id' => $adminGroup->id,
        ]);

        Audit::record(
            'company', $company->id, 'created', $user->id,
            details: "name={$data['name']}",
            newValue: ['name' => $data['name'], 'country' => $company->country, 'currency' => $company->currency, 'timezone' => $company->timezone],
        );

        return response()->json($company->fresh());
    }

    public function update(Request $request, string $companyId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::FULL);

        $company = Company::find($companyId);
        if (! $company) {
            throw new ApiException(404, 'Company not found');
        }
        if (! in_array($company->id, self::accessibleCompanyIds($user), true)) {
            throw new ApiException(403, 'You cannot manage this company.');
        }

        $fields = $request->validate([
            'name' => 'sometimes|string',
            'country' => 'sometimes|string',
            'currency' => 'sometimes|string',
            'timezone' => 'sometimes|string',
            'is_active' => 'sometimes|boolean',
            'logo' => 'sometimes|nullable|string',
            'address' => 'sometimes|nullable|string',
            'gst_registration_no' => 'sometimes|nullable|string',
            'phone' => 'sometimes|nullable|string',
            'website' => 'sometimes|nullable|string',
            'uen' => 'sometimes|nullable|string',
            // Financial year: 1-12. Labelled by the calendar year it
            // ENDS in, so 7 (July) means FY2027 = Jul 2026 - Jun 2027.
            'financial_year_start_month' => 'sometimes|integer|min:1|max:12',
            // This company's own outbound mailbox, for customer-facing
            // document email. Separate from the system mailbox in .env
            // that sends login OTP and password resets, with no
            // fallback either way -- see App\Services\Mailer.
            'smtp_host' => 'sometimes|nullable|string|max:255',
            'smtp_port' => 'sometimes|integer|min:1|max:65535',
            'smtp_username' => 'sometimes|nullable|string|max:255',
            'smtp_password' => 'sometimes|nullable|string',
            'smtp_use_tls' => 'sometimes|boolean',
            'smtp_from_email' => 'sometimes|nullable|email|max:255',
            'smtp_from_name' => 'sometimes|nullable|string|max:255',
        ]);
        if (array_key_exists('logo', $fields)) {
            self::validateLogo($fields['logo']);
        }

        $oldValue = [];
        $newValue = [];
        foreach ($fields as $field => $new) {
            $old = $company->{$field};
            if ($old == $new) {
                continue;
            }
            if ($field === 'logo') {
                // Never dump base64 image data into the audit trail.
                $oldValue[$field] = $old ? '(image set)' : '(none)';
                $newValue[$field] = $new ? '(image set)' : '(none)';
            } elseif ($field === 'smtp_password') {
                // Record THAT it changed, never the credential itself.
                $oldValue[$field] = $old ? '(set)' : '(none)';
                $newValue[$field] = $new ? '(set)' : '(none)';
            } else {
                $oldValue[$field] = $old;
                $newValue[$field] = $new;
            }
            $company->{$field} = $new;
        }

        Audit::record('company', $company->id, 'updated', $user->id, oldValue: $oldValue ?: null, newValue: $newValue ?: null);
        $company->save();

        return response()->json($company->fresh());
    }

    /**
     * Change the company this user is working in. Everything they see
     * is scoped to User.company_id, so this one write re-scopes the
     * whole application for them.
     */
    public function switchCompany(Request $request, string $companyId)
    {
        $user = Authenticate::user($request);

        $company = Company::find($companyId);
        if (! $company || ! $company->is_active) {
            throw new ApiException(404, 'Company not found');
        }
        if (! in_array($company->id, self::accessibleCompanyIds($user), true)) {
            throw new ApiException(403, 'You do not have access to this company.');
        }

        $previous = Company::find($user->company_id);
        if ($company->id !== $user->company_id) {
            Audit::record(
                'user', $user->id, 'switched_company', $user->id,
                oldValue: ['company' => $previous?->name],
                newValue: ['company' => $company->name],
            );
            $user->company_id = $company->id;
            $user->save();
        }

        return response()->json($company->fresh());
    }

    private static function validateLogo(?string $logo): void
    {
        if ($logo === null) {
            return;
        }
        if (! Str::startsWith($logo, 'data:image/')) {
            throw new ApiException(400, "Logo must be an image data URI (e.g. 'data:image/png;base64,...').");
        }
        if (strlen($logo) > self::MAX_LOGO_CHARS) {
            throw new ApiException(400, 'Logo image is too large -- please use an image under ~300 KB.');
        }
    }

    /**
     * Send a test email from this company's own mailbox.
     *
     * Exists so a mailbox is proven working AT SETUP TIME rather than
     * discovered broken when someone emails a real invoice to a real
     * customer. Reports the failure verbatim, since that is what tells
     * an administrator whether the host, the credentials or TLS is
     * wrong.
     */
    public function testEmail(Request $request, string $companyId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, GroupModuleAuthority::FULL);

        $company = Company::find($companyId);
        if (! $company) {
            throw new ApiException(404, 'Company not found');
        }
        if (! in_array($company->id, self::accessibleCompanyIds($user), true)) {
            throw new ApiException(403, 'You cannot manage this company.');
        }

        $data = $request->validate(['to_email' => 'required|email']);

        if (! Mailer::isConfiguredFor($company)) {
            throw new ApiException(422, "Email is not configured for {$company->name}. "
                .'Set its SMTP host and From address first.');
        }

        try {
            Mailer::sendAs(
                $company,
                $data['to_email'],
                "Test email from {$company->name}",
                "This is a test message confirming {$company->name}'s outbound email settings are working.\n\n"
                ."If you received this, document emails from this company will send correctly.\n",
            );
        } catch (MailerException $e) {
            // 502: the settings were accepted, the mail server refused.
            throw new ApiException(502, $e->getMessage());
        }

        Audit::record('company', $company->id, 'smtp_test_sent', $user->id,
            details: "Test email sent to {$data['to_email']}");

        return response()->json(['sent' => true, 'to' => $data['to_email']]);
    }
}
