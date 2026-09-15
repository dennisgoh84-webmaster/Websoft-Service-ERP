<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Api\Concerns\SendsExports;
use App\Http\Controllers\Controller;
use App\Http\Middleware\Authenticate;
use App\Models\AuditLogEntry;
use App\Models\Branch;
use App\Models\CompanyIndividual;
use App\Models\CompanyIndividualGroup;
use App\Models\CompanyIndividualRelationship;
use App\Models\Contact;
use App\Models\PortalUser;
use App\Models\SetupListItem;
use App\Models\User;
use App\Services\Audit;
use App\Services\Authority;
use App\Services\Mailer;
use App\Services\MailerException;
use App\Services\MailerNotConfiguredException;
use App\Services\PasswordPolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * CompanyIndividual Management -- the Customer/Supplier master record,
 * its Contacts and Branches. Mirrors
 * backend/app/routers/company_individuals.py's CRUD, PDPA, archive,
 * Contacts and Branches sections field-for-field and audit-action-for-
 * audit-action.
 *
 * NOT yet converted from the Python router (tracked in
 * docs/php-conversion-plan.md): CSV/Excel export.
 */
class CompanyIndividualController extends Controller
{
    use SendsExports;

    /** @var array<int, string> */
    private const EXPORT_FIELDS = [
        'name', 'customer_type', 'customer_group', 'industry', 'legacy_customer_code',
        'contact_person', 'uen', 'gst_registration_no', 'billing_email', 'phone', 'mobile',
        'address', 'payment_terms_days', 'status',
    ];

    private const MODULE = 'company_individual_management';

    private const MAX_PDPA_DOCUMENT_CHARS = 2_800_000; // ~2 MB of base64

    private const CUSTOMER_FIELDS = [
        'customer_type', 'name', 'customer_group_id', 'legacy_customer_code',
        'contact_person', 'uen', 'gst_registration_no', 'billing_email', 'phone',
        'mobile', 'website', 'address_line1', 'address_line2', 'address_city',
        'address_state', 'address_postal_code', 'address_country', 'tags',
        'industry_code', 'exclude_auto_sent', 'terms_and_conditions', 'memo',
        'billing_notes', 'payment_terms_days', 'data_expiry_date',
        'is_customer', 'is_supplier',
    ];

    private function customerOrFail(User $user, string $customerId): CompanyIndividual
    {
        $customer = CompanyIndividual::find($customerId);
        // Multi-company: another company's customer is "not found" here.
        if (! $customer || $customer->company_id !== $user->company_id) {
            throw new ApiException(404, 'Company / Individual not found');
        }

        return $customer;
    }

    private function contactOrFail(CompanyIndividual $customer, string $contactId): Contact
    {
        $contact = Contact::find($contactId);
        if (! $contact || $contact->customer_id !== $customer->id) {
            throw new ApiException(404, 'Contact not found');
        }

        return $contact;
    }

    private function branchOrFail(CompanyIndividual $customer, string $branchId): Branch
    {
        $branch = Branch::find($branchId);
        if (! $branch || $branch->customer_id !== $customer->id) {
            throw new ApiException(404, 'Branch not found');
        }

        return $branch;
    }

    /**
     * Unlike branchOrFail/contactOrFail above, a relationship's target
     * Contact can belong to ANY CompanyIndividual in this company --
     * not necessarily the one the relationship is being added from.
     */
    private function companyContactOrFail(string $companyId, string $contactId): Contact
    {
        $contact = Contact::with('customer')->find($contactId);
        if (! $contact || $contact->customer?->company_id !== $companyId) {
            throw new ApiException(404, 'Contact not found');
        }

        return $contact;
    }

    private function presentRelationship(CompanyIndividualRelationship $rel): array
    {
        $rel->loadMissing(['toCustomer', 'toContact.customer']);

        return [
            'id' => $rel->id,
            'from_customer_id' => $rel->from_customer_id,
            'to_customer_id' => $rel->to_customer_id,
            'to_customer_name' => $rel->toCustomer?->name,
            'to_customer_type' => $rel->toCustomer?->customer_type,
            'to_contact_id' => $rel->to_contact_id,
            'to_contact_name' => $rel->toContact?->name,
            'to_contact_customer_id' => $rel->toContact?->customer_id,
            'to_contact_customer_name' => $rel->toContact?->customer?->name,
            'relationship_type' => $rel->relationship_type,
            'note' => $rel->note,
            'is_active' => $rel->is_active,
            'created_at' => $rel->created_at,
        ];
    }

    // ---- CompanyIndividual CRUD -----------------------------------------

    public function store(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $data = $this->validateCustomer($request, forCreate: true);
        $customer = CompanyIndividual::create(array_merge($data, ['company_id' => $user->company_id]));

        Audit::record(
            'customer', $customer->id, 'created', $user->id,
            details: "name={$data['name']}",
            newValue: ['name' => $data['name'], 'customer_type' => $data['customer_type']],
        );

        return response()->json($customer->fresh());
    }

    public function update(Request $request, string $customerId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $customer = $this->customerOrFail($user, $customerId);
        $fields = $this->validateCustomer($request, forCreate: false);

        [$oldValue, $newValue] = $this->applyFieldDiff($customer, $fields, self::CUSTOMER_FIELDS);

        Audit::record('customer', $customer->id, 'updated', $user->id, oldValue: $oldValue ?: null, newValue: $newValue ?: null);
        $customer->save();

        return response()->json($customer->fresh());
    }

    public function deactivate(Request $request, string $customerId)
    {
        return $this->toggleActive($request, $customerId, active: false, action: 'deactivated');
    }

    public function reactivate(Request $request, string $customerId)
    {
        return $this->toggleActive($request, $customerId, active: true, action: 'reactivated');
    }

    private function toggleActive(Request $request, string $customerId, bool $active, string $action)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $customer = $this->customerOrFail($user, $customerId);
        $customer->is_active = $active;
        Audit::record(
            'customer', $customer->id, $action, $user->id,
            oldValue: ['is_active' => ! $active], newValue: ['is_active' => $active],
        );
        $customer->save();

        return response()->json($customer->fresh());
    }

    /**
     * Ticks/unticks the "PDPA Agreement e-signed" checkbox. A dedicated
     * endpoint rather than a field on the generic PATCH so the
     * date/time is always stamped by the server, never client-supplied.
     */
    public function pdpaConsent(Request $request, string $customerId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $customer = $this->customerOrFail($user, $customerId);
        $data = $request->validate(['given' => 'required|boolean']);

        $oldGiven = $customer->pdpa_consent_given;
        $customer->pdpa_consent_given = $data['given'];
        $customer->pdpa_consent_at = $data['given'] ? Carbon::now('UTC') : null;

        Audit::record(
            'customer', $customer->id,
            $data['given'] ? 'pdpa_consent_recorded' : 'pdpa_consent_revoked', $user->id,
            oldValue: ['pdpa_consent_given' => $oldGiven],
            newValue: [
                'pdpa_consent_given' => $data['given'],
                'pdpa_consent_at' => $customer->pdpa_consent_at?->toIso8601String(),
            ],
        );
        $customer->save();

        return response()->json($customer->fresh());
    }

    /**
     * Uploads or removes the scanned/photographed/PDF signed PDPA
     * Agreement itself. Security: gated at the same EDIT level as every
     * other write on this record -- this system's access control is
     * per-module, not per-field. The upload/removal itself is written
     * to the audit trail -- never the file content.
     */
    public function pdpaAgreementDocument(Request $request, string $customerId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $customer = $this->customerOrFail($user, $customerId);
        $data = $request->validate(['document' => 'nullable|string']);
        $document = $data['document'] ?? null;
        $this->validatePdpaDocument($document);

        $hadDocument = $customer->pdpa_agreement_document !== null;
        $customer->pdpa_agreement_document = $document;
        $hasDocument = $document !== null;

        if ($hadDocument !== $hasDocument) {
            Audit::record(
                'customer', $customer->id,
                $hasDocument ? 'pdpa_agreement_document_uploaded' : 'pdpa_agreement_document_removed', $user->id,
                oldValue: ['pdpa_agreement_document' => $hadDocument ? '(on file)' : '(none)'],
                newValue: ['pdpa_agreement_document' => $hasDocument ? '(on file)' : '(none)'],
            );
        }
        $customer->save();

        return response()->json($customer->fresh());
    }

    /**
     * Soft-archive-in-place: all of this record's data stays in the
     * same database, same row -- never deleted, per CLAUDE.md's "never
     * permanently delete" rule.
     */
    public function archive(Request $request, string $customerId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $customer = $this->customerOrFail($user, $customerId);
        $customer->is_archived = true;
        $customer->archived_at = Carbon::now('UTC');
        Audit::record(
            'customer', $customer->id, 'archived', $user->id,
            oldValue: ['is_archived' => false], newValue: ['is_archived' => true],
        );
        // PORTAL-004 / design §5: archiving a customer disables every
        // portal login under it, not just on their next request --
        // App\Http\Middleware\AuthenticatePortal also refuses live
        // tokens once the customer is archived, but this keeps the
        // Contacts tab's own status display accurate too.
        $portalUsers = PortalUser::query()
            ->join('contacts', 'portal_users.contact_id', '=', 'contacts.id')
            ->where('contacts.customer_id', $customer->id)
            ->where('portal_users.is_active', true)
            ->select('portal_users.*')
            ->get();
        foreach ($portalUsers as $portalUser) {
            $this->disablePortalUser($portalUser, actorUserId: $user->id);
        }
        $customer->save();

        return response()->json($customer->fresh());
    }

    public function unarchive(Request $request, string $customerId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'full');

        $customer = $this->customerOrFail($user, $customerId);
        $customer->is_archived = false;
        $customer->archived_at = null;
        Audit::record(
            'customer', $customer->id, 'unarchived', $user->id,
            oldValue: ['is_archived' => true], newValue: ['is_archived' => false],
        );
        $customer->save();

        return response()->json($customer->fresh());
    }

    /**
     * Dynamic filter for the CompanyIndividual master: free-text `q`
     * matches across name/email/phone/mobile/UEN/legacy code/tags.
     */
    public function index(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->filtered($user->company_id, $request);
    }

    /**
     * The list the screen shows, honouring every filter -- shared with
     * the exports so an Export button always returns what is on
     * screen.
     *
     * @return Collection<int, CompanyIndividual>
     */
    private function filtered(string $companyId, Request $request)
    {
        $query = CompanyIndividual::where('company_id', $companyId);

        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }
        if (! $request->boolean('include_archived')) {
            $query->where('is_archived', false);
        }
        if ($request->filled('customer_group_id')) {
            $query->where('customer_group_id', $request->query('customer_group_id'));
        }
        if ($request->filled('industry_code')) {
            $query->where('industry_code', $request->query('industry_code'));
        }
        if ($request->has('is_supplier')) {
            $query->where('is_supplier', $request->boolean('is_supplier'));
        }
        if ($request->filled('q')) {
            $like = '%'.$request->query('q').'%';
            $query->where(function ($w) use ($like) {
                $w->where('name', 'ilike', $like)
                    ->orWhere('billing_email', 'ilike', $like)
                    ->orWhere('phone', 'ilike', $like)
                    ->orWhere('mobile', 'ilike', $like)
                    ->orWhere('contact_person', 'ilike', $like)
                    ->orWhere('uen', 'ilike', $like)
                    ->orWhere('legacy_customer_code', 'ilike', $like)
                    ->orWhere('tags', 'ilike', $like);
            });
        }

        return $query->orderBy('name')->get();
    }

    /** @return array<int, array<string, mixed>> */
    private function exportRows(string $companyId, Request $request): array
    {
        $groupNames = CompanyIndividualGroup::where('company_id', $companyId)->pluck('name', 'id');
        $industryNames = SetupListItem::where('list_type', SetupListItem::TYPE_INDUSTRY)->pluck('name', 'code');

        return $this->filtered($companyId, $request)->map(function (CompanyIndividual $c) use ($groupNames, $industryNames) {
            // One flattened address column: a spreadsheet reader wants
            // the address, not six columns that are usually blank.
            $address = implode(', ', array_filter([
                $c->address_line1, $c->address_line2, $c->address_city,
                $c->address_state, $c->address_postal_code, $c->address_country,
            ]));

            return [
                'name' => $c->name,
                'customer_type' => $c->customer_type,
                'customer_group' => $groupNames[$c->customer_group_id] ?? '',
                'industry' => $industryNames[$c->industry_code] ?? '',
                'legacy_customer_code' => $c->legacy_customer_code ?? '',
                'contact_person' => $c->contact_person ?? '',
                'uen' => $c->uen ?? '',
                'gst_registration_no' => $c->gst_registration_no ?? '',
                'billing_email' => $c->billing_email ?? '',
                'phone' => $c->phone ?? '',
                'mobile' => $c->mobile ?? '',
                'address' => $address,
                'payment_terms_days' => $c->payment_terms_days ?? '',
                'status' => $c->is_active ? 'active' : 'inactive',
            ];
        })->all();
    }

    public function exportCsv(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->csvResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request), 'company-individuals.csv'
        );
    }

    public function exportExcel(Request $request)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return $this->xlsxResponse(
            self::EXPORT_FIELDS, $this->exportRows($user->company_id, $request),
            'Company Individuals', 'company-individuals.xlsx'
        );
    }

    public function show(Request $request, string $customerId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        return response()->json($this->customerOrFail($user, $customerId));
    }

    public function auditLog(Request $request, string $customerId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $this->customerOrFail($user, $customerId);

        return AuditLogEntry::where('entity_type', 'customer')
            ->where('entity_id', $customerId)
            ->orderByDesc('at')
            ->limit(50)
            ->get();
    }

    // ---- Contacts ----------------------------------------------------

    public function listContacts(Request $request, string $customerId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $customer = $this->customerOrFail($user, $customerId);
        $query = Contact::where('customer_id', $customer->id);
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return $query->orderBy('name')->get();
    }

    public function createContact(Request $request, string $customerId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $customer = $this->customerOrFail($user, $customerId);
        $data = $request->validate([
            'name' => 'required|string',
            'email' => 'sometimes|nullable|string',
            'phone' => 'sometimes|nullable|string',
            'direct_line' => 'sometimes|nullable|string',
        ]);

        $contact = Contact::create(array_merge($data, ['customer_id' => $customer->id]));

        Audit::record(
            'contact', $contact->id, 'created', $user->id,
            details: "customer={$customer->name}, name={$data['name']}",
            newValue: $data,
        );

        return response()->json($contact->fresh());
    }

    public function updateContact(Request $request, string $customerId, string $contactId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $customer = $this->customerOrFail($user, $customerId);
        $contact = $this->contactOrFail($customer, $contactId);
        $fields = $request->validate([
            'name' => 'sometimes|string',
            'email' => 'sometimes|nullable|string',
            'phone' => 'sometimes|nullable|string',
            'direct_line' => 'sometimes|nullable|string',
        ]);

        [$oldValue, $newValue] = $this->applyFieldDiff($contact, $fields, ['name', 'email', 'phone', 'direct_line']);

        Audit::record('contact', $contact->id, 'updated', $user->id, oldValue: $oldValue ?: null, newValue: $newValue ?: null);
        $contact->save();

        return response()->json($contact->fresh());
    }

    public function deactivateContact(Request $request, string $customerId, string $contactId)
    {
        return $this->toggleContactActive($request, $customerId, $contactId, active: false, action: 'deactivated');
    }

    public function reactivateContact(Request $request, string $customerId, string $contactId)
    {
        return $this->toggleContactActive($request, $customerId, $contactId, active: true, action: 'reactivated');
    }

    private function toggleContactActive(Request $request, string $customerId, string $contactId, bool $active, string $action)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $customer = $this->customerOrFail($user, $customerId);
        $contact = $this->contactOrFail($customer, $contactId);
        $contact->is_active = $active;
        Audit::record(
            'contact', $contact->id, $action, $user->id,
            oldValue: ['is_active' => ! $active], newValue: ['is_active' => $active],
        );
        $contact->save();

        return response()->json($contact->fresh());
    }

    // ---- Customer Helpdesk Portal access (PORTAL-001..004, design §5) ----
    // Enable/disable/reset a Contact's login to the separate /portal
    // frontend. Lives here (not on PortalAuthController/PortalController)
    // because it's a staff action gated by this module's own EDIT
    // authority, not something a customer can reach -- the portal
    // controllers are customer-facing only ('auth.portal'), this is
    // company_individual_management-facing. Same split, and the same
    // reason, as backend/app/routers/company_individuals.py.

    /**
     * 10 random alphanumeric characters, regenerated until it satisfies
     * PasswordPolicy::validateComplexity's letter+digit rule (near-
     * certain on the first try, but never assumed).
     */
    private static function generateTempPassword(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        while (true) {
            $pw = '';
            for ($i = 0; $i < 10; $i++) {
                $pw .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            if (preg_match('/[A-Za-z]/', $pw) && preg_match('/[0-9]/', $pw)) {
                return $pw;
            }
        }
    }

    /**
     * Staff-side view of one Contact's portal login (design §5) --
     * returned by the enable/disable/reset-password actions and by the
     * plain GET so the Contacts tab can show current status without the
     * staff member having to trigger an action first.
     *
     * @return array<string, mixed>
     */
    private function portalAccessOut(?PortalUser $portalUser, ?string $temporaryPassword = null, bool $invitedByEmail = false): array
    {
        if ($portalUser === null) {
            return [
                'enabled' => false,
                'email' => null,
                'must_change_password' => null,
                'last_login_at' => null,
                'locked' => false,
                'temporary_password' => null,
                'invited_by_email' => false,
            ];
        }
        $now = Carbon::now('UTC');

        return [
            'enabled' => $portalUser->is_active,
            'email' => $portalUser->email,
            'must_change_password' => $portalUser->must_change_password,
            'last_login_at' => $portalUser->last_login_at,
            'locked' => (bool) ($portalUser->locked_until && $portalUser->locked_until->gt($now)),
            // Only present immediately after enable/reset, and only when
            // SMTP isn't configured -- the one-time display fallback.
            'temporary_password' => $temporaryPassword,
            'invited_by_email' => $invitedByEmail,
        ];
    }

    public function getPortalAccess(Request $request, string $customerId, string $contactId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $customer = $this->customerOrFail($user, $customerId);
        $contact = $this->contactOrFail($customer, $contactId);

        return response()->json($this->portalAccessOut(PortalUser::where('contact_id', $contact->id)->first()));
    }

    /**
     * Grants (or re-grants, if it was disabled) this Contact a Helpdesk
     * Portal login. Refuses without a contact email, without PDPA
     * consent on file, or if the customer is archived (design §5,
     * PORTAL-004).
     */
    public function enablePortalAccess(Request $request, string $customerId, string $contactId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $customer = $this->customerOrFail($user, $customerId);
        $contact = $this->contactOrFail($customer, $contactId);
        if (! $contact->email) {
            throw new ApiException(422, 'This contact has no email address -- add one before enabling portal access.');
        }
        if ($customer->is_archived) {
            throw new ApiException(422, 'This customer is archived -- unarchive it before enabling portal access.');
        }
        if (! $customer->pdpa_consent_given) {
            throw new ApiException(422, 'PDPA consent has not been recorded for this customer -- record consent before enabling portal access.');
        }

        $portalUser = PortalUser::where('contact_id', $contact->id)->first();
        $tempPassword = self::generateTempPassword();
        if ($portalUser === null) {
            $portalUser = PortalUser::create([
                'company_id' => $customer->company_id,
                'contact_id' => $contact->id,
                'email' => $contact->email,
                'hashed_password' => PasswordPolicy::hash($tempPassword),
                'is_active' => true,
                'must_change_password' => true,
                'created_by_user_id' => $user->id,
            ]);
            $auditAction = 'portal_access_enabled';
        } else {
            $portalUser->email = $contact->email;
            $portalUser->hashed_password = PasswordPolicy::hash($tempPassword);
            $portalUser->is_active = true;
            $portalUser->must_change_password = true;
            $portalUser->failed_attempts = 0;
            $portalUser->locked_until = null;
            $portalUser->save();
            $auditAction = 'portal_access_re_enabled';
        }

        $invitedByEmail = false;
        if (Mailer::isConfigured()) {
            try {
                Mailer::send(
                    $portalUser->email,
                    'Your Websoft Helpdesk Portal access',
                    "Hi {$contact->name},\n\n".
                    "You now have access to the {$customer->name} Helpdesk Portal.\n\n".
                    "Sign in at the portal login page with:\n".
                    "  Email: {$portalUser->email}\n".
                    "  Temporary password: {$tempPassword}\n\n".
                    "You'll be asked to set your own password the first time you sign in."
                );
                $invitedByEmail = true;
            } catch (MailerNotConfiguredException|MailerException) {
                // fall through to showing it on screen below
                $invitedByEmail = false;
            }
        }

        Audit::record(
            'portal_user', $portalUser->id, $auditAction, $user->id,
            details: "contact={$contact->name}, customer={$customer->name}",
        );

        return response()->json($this->portalAccessOut(
            $portalUser->fresh(),
            temporaryPassword: $invitedByEmail ? null : $tempPassword,
            invitedByEmail: $invitedByEmail,
        ));
    }

    public function resetPortalAccessPassword(Request $request, string $customerId, string $contactId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $customer = $this->customerOrFail($user, $customerId);
        $contact = $this->contactOrFail($customer, $contactId);
        $portalUser = PortalUser::where('contact_id', $contact->id)->first();
        if ($portalUser === null) {
            throw new ApiException(404, 'This contact does not have portal access yet.');
        }

        $tempPassword = self::generateTempPassword();
        $portalUser->hashed_password = PasswordPolicy::hash($tempPassword);
        $portalUser->must_change_password = true;
        $portalUser->failed_attempts = 0;
        $portalUser->locked_until = null;
        $portalUser->save();

        $invitedByEmail = false;
        if (Mailer::isConfigured()) {
            try {
                Mailer::send(
                    $portalUser->email,
                    'Your Websoft Helpdesk Portal password has been reset',
                    "Hi {$contact->name},\n\n".
                    "Your Helpdesk Portal password has been reset.\n\n".
                    "  Email: {$portalUser->email}\n".
                    "  Temporary password: {$tempPassword}\n\n".
                    "You'll be asked to set your own password the next time you sign in."
                );
                $invitedByEmail = true;
            } catch (MailerNotConfiguredException|MailerException) {
                $invitedByEmail = false;
            }
        }

        Audit::record(
            'portal_user', $portalUser->id, 'portal_password_reset_by_staff', $user->id,
            details: "contact={$contact->name}, customer={$customer->name}",
        );

        return response()->json($this->portalAccessOut(
            $portalUser->fresh(),
            temporaryPassword: $invitedByEmail ? null : $tempPassword,
            invitedByEmail: $invitedByEmail,
        ));
    }

    public function disablePortalAccess(Request $request, string $customerId, string $contactId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $customer = $this->customerOrFail($user, $customerId);
        $contact = $this->contactOrFail($customer, $contactId);
        $portalUser = PortalUser::where('contact_id', $contact->id)->first();
        if ($portalUser === null) {
            throw new ApiException(404, 'This contact does not have portal access.');
        }
        $this->disablePortalUser($portalUser, actorUserId: $user->id);

        return response()->json($this->portalAccessOut($portalUser->fresh()));
    }

    /**
     * Shared by the explicit disable action above and by archive()
     * (design §5: "Archiving a customer calls the same disable for
     * every portal user under it").
     */
    private function disablePortalUser(PortalUser $portalUser, ?string $actorUserId): void
    {
        $portalUser->is_active = false;
        $portalUser->save();
        Audit::record('portal_user', $portalUser->id, 'portal_access_disabled', $actorUserId);
    }

    // ---- Branches ------------------------------------------------------

    public function listBranches(Request $request, string $customerId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $customer = $this->customerOrFail($user, $customerId);
        $query = Branch::where('customer_id', $customer->id);
        if (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }

        return $query->orderBy('branch_name')->get();
    }

    public function createBranch(Request $request, string $customerId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $customer = $this->customerOrFail($user, $customerId);
        $data = $request->validate([
            'branch_name' => 'required|string',
            'branch_code' => 'sometimes|nullable|string',
            'address_line1' => 'sometimes|nullable|string',
            'address_line2' => 'sometimes|nullable|string',
            'address_city' => 'sometimes|nullable|string',
            'address_state' => 'sometimes|nullable|string',
            'address_postal_code' => 'sometimes|nullable|string',
            'address_country' => 'sometimes|nullable|string',
            'phone' => 'sometimes|nullable|string',
        ]);

        $branch = Branch::create(array_merge($data, ['customer_id' => $customer->id]));

        Audit::record(
            'branch', $branch->id, 'created', $user->id,
            details: "customer={$customer->name}, branch={$data['branch_name']}",
            newValue: ['branch_name' => $data['branch_name'], 'branch_code' => $data['branch_code'] ?? null],
        );

        return response()->json($branch->fresh());
    }

    public function updateBranch(Request $request, string $customerId, string $branchId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $customer = $this->customerOrFail($user, $customerId);
        $branch = $this->branchOrFail($customer, $branchId);
        $fields = $request->validate([
            'branch_name' => 'sometimes|string',
            'branch_code' => 'sometimes|nullable|string',
            'address_line1' => 'sometimes|nullable|string',
            'address_line2' => 'sometimes|nullable|string',
            'address_city' => 'sometimes|nullable|string',
            'address_state' => 'sometimes|nullable|string',
            'address_postal_code' => 'sometimes|nullable|string',
            'address_country' => 'sometimes|nullable|string',
            'phone' => 'sometimes|nullable|string',
        ]);

        [$oldValue, $newValue] = $this->applyFieldDiff($branch, $fields, [
            'branch_name', 'branch_code', 'address_line1', 'address_line2', 'address_city',
            'address_state', 'address_postal_code', 'address_country', 'phone',
        ]);

        Audit::record('branch', $branch->id, 'updated', $user->id, oldValue: $oldValue ?: null, newValue: $newValue ?: null);
        $branch->save();

        return response()->json($branch->fresh());
    }

    public function deactivateBranch(Request $request, string $customerId, string $branchId)
    {
        return $this->toggleBranchActive($request, $customerId, $branchId, active: false, action: 'deactivated');
    }

    public function reactivateBranch(Request $request, string $customerId, string $branchId)
    {
        return $this->toggleBranchActive($request, $customerId, $branchId, active: true, action: 'reactivated');
    }

    private function toggleBranchActive(Request $request, string $customerId, string $branchId, bool $active, string $action)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $customer = $this->customerOrFail($user, $customerId);
        $branch = $this->branchOrFail($customer, $branchId);
        $branch->is_active = $active;
        Audit::record(
            'branch', $branch->id, $action, $user->id,
            oldValue: ['is_active' => ! $active], newValue: ['is_active' => $active],
        );
        $branch->save();

        return response()->json($branch->fresh());
    }

    // ---- Relationships (company/individual/contact links) --------------
    // See CompanyIndividualRelationship's own docstring for why there's
    // no separate "level" field and why this is undirected.

    public function listRelationships(Request $request, string $customerId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'view');

        $customer = $this->customerOrFail($user, $customerId);
        $rels = CompanyIndividualRelationship::where('from_customer_id', $customer->id)
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->get();

        return $rels->map(fn ($r) => $this->presentRelationship($r))->values();
    }

    public function createRelationship(Request $request, string $customerId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $customer = $this->customerOrFail($user, $customerId);
        $data = $request->validate([
            'to_customer_id' => 'sometimes|nullable|uuid',
            'to_contact_id' => 'sometimes|nullable|uuid',
            'relationship_type' => 'required|string',
            'note' => 'sometimes|nullable|string',
        ]);
        $toCustomerId = $data['to_customer_id'] ?? null;
        $toContactId = $data['to_contact_id'] ?? null;
        if (($toCustomerId !== null) === ($toContactId !== null)) {
            throw new ApiException(422, 'Link to exactly one of another Company/Individual or a Contact.');
        }
        if ($toCustomerId) {
            $target = $this->customerOrFail($user, $toCustomerId);
            if ($target->id === $customer->id) {
                throw new ApiException(422, 'A record cannot be related to itself.');
            }
        }
        if ($toContactId) {
            $this->companyContactOrFail($user->company_id, $toContactId);
        }

        $rel = CompanyIndividualRelationship::create([
            'company_id' => $user->company_id,
            'from_customer_id' => $customer->id,
            'to_customer_id' => $toCustomerId,
            'to_contact_id' => $toContactId,
            'relationship_type' => $data['relationship_type'],
            'note' => $data['note'] ?? null,
            'created_by_user_id' => $user->id,
        ]);

        Audit::record(
            'customer_relationship', $rel->id, 'created', $user->id,
            details: "from={$customer->name}, type={$data['relationship_type']}",
            newValue: [
                'to_customer_id' => $toCustomerId,
                'to_contact_id' => $toContactId,
                'relationship_type' => $data['relationship_type'],
            ],
        );

        return response()->json($this->presentRelationship($rel->fresh()));
    }

    public function deactivateRelationship(Request $request, string $customerId, string $relationshipId)
    {
        $user = Authenticate::user($request);
        Authority::requireModuleAccess($user, self::MODULE, 'edit');

        $customer = $this->customerOrFail($user, $customerId);
        $rel = CompanyIndividualRelationship::find($relationshipId);
        if (! $rel || $rel->from_customer_id !== $customer->id) {
            throw new ApiException(404, 'Relationship not found');
        }

        $rel->is_active = false;
        Audit::record(
            'customer_relationship', $rel->id, 'deactivated', $user->id,
            oldValue: ['is_active' => true], newValue: ['is_active' => false],
        );
        $rel->save();

        return response()->json($this->presentRelationship($rel->fresh()));
    }

    // ---- Shared helpers --------------------------------------------------

    private function validateCustomer(Request $request, bool $forCreate): array
    {
        $rules = [
            'customer_type' => ($forCreate ? 'sometimes' : 'sometimes').'|in:individual,company',
            'name' => ($forCreate ? 'required' : 'sometimes').'|string',
            'customer_group_id' => 'sometimes|nullable|uuid',
            'legacy_customer_code' => 'sometimes|nullable|string',
            'contact_person' => 'sometimes|nullable|string',
            'uen' => 'sometimes|nullable|string',
            'gst_registration_no' => 'sometimes|nullable|string',
            'billing_email' => 'sometimes|nullable|string',
            'phone' => 'sometimes|nullable|string',
            'mobile' => 'sometimes|nullable|string',
            'website' => 'sometimes|nullable|string',
            'address_line1' => 'sometimes|nullable|string',
            'address_line2' => 'sometimes|nullable|string',
            'address_city' => 'sometimes|nullable|string',
            'address_state' => 'sometimes|nullable|string',
            'address_postal_code' => 'sometimes|nullable|string',
            'address_country' => 'sometimes|nullable|string',
            'tags' => 'sometimes|nullable|string',
            'industry_code' => 'sometimes|nullable|string',
            'exclude_auto_sent' => 'sometimes|boolean',
            'terms_and_conditions' => 'sometimes|nullable|string',
            'memo' => 'sometimes|nullable|string',
            'billing_notes' => 'sometimes|nullable|string',
            'payment_terms_days' => 'sometimes|nullable|integer',
            'data_expiry_date' => 'sometimes|nullable|date',
            // A record can be a customer, a supplier, or both -- see
            // CompanyIndividual's model docstring (2026-09-12: folded
            // the former standalone Supplier table into this one as a
            // role flag). is_customer defaults true since that's this
            // page's usual purpose; is_supplier defaults false.
            'is_customer' => 'sometimes|boolean',
            'is_supplier' => 'sometimes|boolean',
        ];
        $data = $request->validate($rules);
        if ($forCreate && ! array_key_exists('customer_type', $data)) {
            $data['customer_type'] = CompanyIndividual::TYPE_COMPANY;
        }
        if ($forCreate && ! array_key_exists('is_customer', $data)) {
            $data['is_customer'] = true;
        }
        if ($forCreate && ! array_key_exists('is_supplier', $data)) {
            $data['is_supplier'] = false;
        }

        return $data;
    }

    private function validatePdpaDocument(?string $document): void
    {
        if ($document === null) {
            return;
        }
        if (! Str::startsWith($document, ['data:image/', 'data:application/pdf'])) {
            throw new ApiException(400, "The signed agreement must be an image or PDF (e.g. 'data:image/png;base64,...' or 'data:application/pdf;base64,...').");
        }
        if (strlen($document) > self::MAX_PDPA_DOCUMENT_CHARS) {
            throw new ApiException(400, 'That file is too large -- please use one under ~2 MB.');
        }
    }

    /**
     * Applies only the fields present in $fields (partial-update
     * semantics, like Pydantic's exclude_unset) that actually changed,
     * returning [oldValue, newValue] diffs for the audit trail.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function applyFieldDiff($model, array $fields, array $allowedFields): array
    {
        $oldValue = [];
        $newValue = [];
        foreach ($allowedFields as $field) {
            if (! array_key_exists($field, $fields)) {
                continue;
            }
            $old = $model->{$field};
            $new = $fields[$field];
            if ($old == $new) {
                continue;
            }
            $oldValue[$field] = $old;
            $newValue[$field] = $new;
            $model->{$field} = $new;
        }

        return [$oldValue, $newValue];
    }
}
