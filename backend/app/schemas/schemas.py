"""
Pydantic request/response schemas for the Service Operations core API.

Hours are exposed to the API/UI as hours (float); the DB stores minutes
internally (see app/models/contracts.py) to keep SRV-007 rounding exact.
"""
import uuid
from datetime import date, datetime

from pydantic import BaseModel, ConfigDict, Field

from app.models.accounting import AccountType, JournalStatus, VoucherType
from app.models.catalog import ProductType
from app.models.payables import BillMatchStatus, BillStatus, PurchaseOrder, PurchaseOrderStatus
from app.models.contracts import ContractKind, ContractStatus, ExcessTreatment, LicenseDeploymentType
from app.models.company_individuals import CompanyIndividualType
from app.models.quotations import QuotationStatus
from app.models.core import UserRole
from app.models.groups import AccessLevel
from app.models.incidents import IncidentSource, IncidentStatus
from app.models.job_orders import JobOrderPriority, JobOrderStatus, JobOrderType, MilestoneStatus, MilestoneType
from app.models.licensing import LicenseType
from app.models.service_records import ServiceRecordCompletion, ServiceRecordOutcome, ServiceRecordStatus
from app.models.setup import SetupListType
from app.models.periods import PeriodDocType, PeriodOperation, PeriodStatus
from app.models.ops_tasks import OpsTaskStatus
from app.models.documents import DocumentEntityType
from app.models.approvals import ApprovalDecisionValue, ApprovalMode, ApprovalStatus


# ---- Auth ----
class Token(BaseModel):
    access_token: str
    token_type: str = "bearer"


class LoginResult(BaseModel):
    """The response to /auth/login, /auth/verify-otp and /auth/change-
    password (2026-09-12: forced first-login password change + email
    OTP). Only one of the three token fields is ever set, matching
    `status`:
    - "ok": `access_token` -- signed in, no further step needed.
    - "must_change_password": `change_token` -- a first-login (or post-
      admin-reset) account must set its own password via
      POST /auth/change-password before it can sign in.
    - "otp_required": `otp_token` -- a 6-digit code was emailed; call
      POST /auth/verify-otp with it to get the real access_token.
    Every non-access token here carries a "purpose" claim so it can
    never be used as a bearer token even if intercepted -- see
    app/services/auth.py.
    """

    status: str  # "ok" | "must_change_password" | "otp_required"
    access_token: str | None = None
    token_type: str = "bearer"
    change_token: str | None = None
    otp_token: str | None = None


class VerifyOtpRequest(BaseModel):
    otp_token: str
    code: str = Field(min_length=6, max_length=6)


class ChangePasswordRequest(BaseModel):
    change_token: str
    new_password: str = Field(min_length=8)


class MessageResponse(BaseModel):
    """A plain confirmation message -- used where the response must be
    identical whether or not the request "worked" in some sensitive
    sense (e.g. forgot-password: never confirm or deny an email exists)."""

    message: str


class ForgotPasswordRequest(BaseModel):
    email: str


class ResetPasswordWithOtpRequest(BaseModel):
    """Completes "forgot password": proves the caller controls the
    account's email by quoting the code just sent to it, then sets a
    new password directly -- no separate change_token/otp_token step
    since email+code together already establish who this is."""

    email: str
    code: str = Field(min_length=6, max_length=6)
    new_password: str = Field(min_length=8)


# ---- Customer Helpdesk Portal auth (PORTAL-001..004) -------------------
# Deliberately its own small set of schemas, mirroring the staff ones
# above, rather than reusing them -- the portal login takes JSON
# (not an OAuth2 form) and its token/flow shape is simpler (see
# docs/customer-portal-design.md §4): login always challenges by OTP
# (or signs straight in if SMTP isn't configured, same fail-open as
# staff), and verify-otp hands back the one long-lived portal bearer
# token -- must_change_password is a flag the frontend acts on by
# routing to the change-password screen, not a second gate on the API.


class PortalLoginRequest(BaseModel):
    email: str
    password: str


class PortalLoginResult(BaseModel):
    status: str  # "otp_required" | "ok"
    otp_token: str | None = None
    portal_token: str | None = None
    must_change_password: bool = False


class PortalVerifyOtpRequest(BaseModel):
    otp_token: str
    code: str = Field(min_length=6, max_length=6)


class PortalChangePasswordRequest(BaseModel):
    new_password: str = Field(min_length=8)


class PortalForgotPasswordRequest(BaseModel):
    email: str


class PortalResetPasswordWithOtpRequest(BaseModel):
    email: str
    code: str = Field(min_length=6, max_length=6)
    new_password: str = Field(min_length=8)


class PortalMeOut(BaseModel):
    contact_name: str
    email: str
    customer_name: str
    must_change_password: bool


class PortalAccessOut(BaseModel):
    """Staff-side view of one Contact's portal login (design §5) --
    returned by the enable/disable/reset-password actions and by the
    plain GET so the Contacts tab can show current status without the
    staff member having to trigger an action first."""

    enabled: bool
    email: str | None = None
    must_change_password: bool | None = None
    last_login_at: datetime | None = None
    locked: bool = False
    # Only present immediately after enable/reset, and only when SMTP
    # isn't configured -- the one-time display fallback (design §5).
    temporary_password: str | None = None
    invited_by_email: bool = False


# ---- Customer Helpdesk Portal data (PORTAL-002) -------------------------
# Deliberately thin views over the same records staff see -- never the
# full *Out schemas above, per design §6's "deliberately not exposed"
# list: no money (invoice/quotation/rate/cost), no internal notes, no
# raw vs deducted minutes (just the rounded SRV-007 figure), no other
# contacts, no staff names beyond the one engineer assigned to the work.


class PortalContractOut(BaseModel):
    id: uuid.UUID
    contract_number: str
    contract_kind: ContractKind
    status: ContractStatus
    contracted_hours: float
    consumed_hours: float
    remaining_hours: float
    start_date: date
    end_date: date

    @classmethod
    def from_model(cls, contract) -> "PortalContractOut":
        return cls(
            id=contract.id,
            contract_number=contract.contract_number,
            contract_kind=contract.contract_kind,
            status=contract.status,
            contracted_hours=contract.contracted_minutes / 60,
            consumed_hours=contract.consumed_minutes / 60,
            remaining_hours=contract.remaining_minutes / 60,
            start_date=contract.start_date,
            end_date=contract.end_date,
        )


class PortalJobOrderOut(BaseModel):
    id: uuid.UUID
    job_order_number: str
    subject: str
    job_order_type: JobOrderType
    status: JobOrderStatus
    assigned_engineer_name: str | None
    due_date: date | None


class PortalServiceRecordOut(BaseModel):
    id: uuid.UUID
    service_record_number: str
    job_order_id: uuid.UUID
    job_order_number: str
    work_date: date
    engineer_name: str
    minutes: int  # rounded_minutes (SRV-007) -- never raw or deducted, see module docstring
    completion_status: ServiceRecordCompletion
    status: ServiceRecordStatus


class PortalJobOrderDetailOut(PortalJobOrderOut):
    service_records: list[PortalServiceRecordOut] = Field(default_factory=list)


class PortalIncidentOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    incident_number: str
    subject: str
    description: str | None
    status: IncidentStatus
    created_at: datetime


class PortalIncidentCreate(BaseModel):
    subject: str = Field(min_length=1, max_length=255)
    description: str | None = None


class CurrentUser(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    full_name: str
    email: str
    role: UserRole
    group_id: uuid.UUID | None = None
    # The company this user is currently working in (multi-company).
    company_id: uuid.UUID | None = None


# ---- Company Setup / multi-company ----
class CompanyOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    name: str
    country: str
    currency: str
    timezone: str
    logo: str | None
    # Shown on tax invoices.
    address: str | None
    gst_registration_no: str | None
    # Shown on printed forms' letterhead (Company Dashboard/Setup).
    phone: str | None
    website: str | None
    uen: str | None
    # Approval thresholds -- null means "always require owner approval",
    # since the values were never decided (open items 2.7 / 3.4 / 4.4).
    write_off_approval_threshold_sgd: float | None
    credit_note_approval_threshold_sgd: float | None
    po_approval_threshold_sgd: float | None
    is_active: bool
    created_at: datetime


class PublicBrandingOut(BaseModel):
    """Shown on the Login page, before anyone is signed in -- deliberately
    a minimal, separate shape from CompanyOut so nothing sensitive
    (address, UEN, GST no., approval thresholds) is ever exposed on an
    unauthenticated endpoint. See app/routers/companies.py's
    /public-branding."""

    name: str
    logo: str | None


class CompanyCreate(BaseModel):
    name: str = Field(min_length=1)
    country: str = "Singapore"
    currency: str = "SGD"
    timezone: str = "Asia/Singapore"
    logo: str | None = None


class CompanyUpdate(BaseModel):
    name: str | None = None
    country: str | None = None
    currency: str | None = None
    timezone: str | None = None
    # A data URI ("data:image/png;base64,..."). Pass null to clear the logo.
    logo: str | None = None
    address: str | None = None
    gst_registration_no: str | None = None
    phone: str | None = None
    website: str | None = None
    uen: str | None = None
    write_off_approval_threshold_sgd: float | None = None
    credit_note_approval_threshold_sgd: float | None = None
    po_approval_threshold_sgd: float | None = None
    is_active: bool | None = None


# ---- Staff Master (Users) ----
class UserOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    full_name: str
    email: str
    role: UserRole
    group_id: uuid.UUID | None
    # Confirmed 2026-09-11: shown on Staff Master and Support Monitoring.
    # A data URI, same inline-image pattern as Company.logo.
    photo: str | None
    # Confirmed 2026-09-12: lets Staff Master flag accounts that still
    # need to set their own password (new hires, and anyone whose
    # password an admin just reset).
    must_change_password: bool
    is_active: bool
    created_at: datetime


class UserCreate(BaseModel):
    full_name: str
    email: str
    password: str = Field(min_length=8)
    role: UserRole
    group_id: uuid.UUID | None = None


class UserUpdate(BaseModel):
    full_name: str | None = None
    role: UserRole | None = None
    group_id: uuid.UUID | None = None
    photo: str | None = None


class UserPasswordReset(BaseModel):
    new_password: str = Field(min_length=8)


class UserCompanyAccessOut(BaseModel):
    """One company a staff member may work in, and their Group there."""

    company_id: uuid.UUID
    company_name: str
    group_id: uuid.UUID | None
    group_name: str | None


class UserCompanyAccessEntry(BaseModel):
    company_id: uuid.UUID
    group_id: uuid.UUID | None = None


class UserCompanyAccessUpdate(BaseModel):
    """Replaces the full set of companies this staff member may work in
    (and their Group in each). Omitting a company revokes its access."""

    access: list[UserCompanyAccessEntry]


class AuditLogEntryOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    entity_type: str
    entity_id: uuid.UUID
    action: str
    actor_user_id: uuid.UUID | None
    actor_name: str | None
    reason: str | None
    details: str | None
    old_value: str | None
    new_value: str | None
    ip_address: str | None
    user_agent: str | None
    device_id: str | None
    at: datetime


# ---- Group Authority ----
class GroupAuthorityOut(BaseModel):
    module_key: str
    access_level: AccessLevel


class GroupOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    name: str
    description: str | None
    created_at: datetime
    authorities: list[GroupAuthorityOut] = []
    member_count: int = 0

    @classmethod
    def from_model(cls, group, member_count: int = 0) -> "GroupOut":
        return cls(
            id=group.id,
            name=group.name,
            description=group.description,
            created_at=group.created_at,
            authorities=[
                GroupAuthorityOut(module_key=a.module_key, access_level=a.access_level)
                for a in group.authorities
            ],
            member_count=member_count,
        )


class GroupCreate(BaseModel):
    name: str
    description: str | None = None


class GroupUpdate(BaseModel):
    name: str | None = None
    description: str | None = None


class GroupAuthoritySet(BaseModel):
    module_key: str
    access_level: AccessLevel


class GroupAuthoritiesUpdateRequest(BaseModel):
    authorities: list[GroupAuthoritySet]


# ---- CompanyIndividual Groups (tag linking separate companies in one group) --
class CompanyIndividualGroupCreate(BaseModel):
    name: str
    description: str | None = None


class CompanyIndividualGroupUpdate(BaseModel):
    name: str | None = None
    description: str | None = None
    is_active: bool | None = None


class CompanyIndividualGroupOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    name: str
    description: str | None
    is_active: bool
    created_at: datetime


# ---- CompanyIndividuals ----
class CompanyIndividualCreate(BaseModel):
    customer_type: CompanyIndividualType = CompanyIndividualType.company
    name: str
    customer_group_id: uuid.UUID | None = None
    legacy_customer_code: str | None = None
    contact_person: str | None = None
    uen: str | None = None
    gst_registration_no: str | None = None
    billing_email: str | None = None
    phone: str | None = None
    mobile: str | None = None
    website: str | None = None
    address_line1: str | None = None
    address_line2: str | None = None
    address_city: str | None = None
    address_state: str | None = None
    address_postal_code: str | None = None
    address_country: str | None = None
    tags: str | None = None
    industry_code: str | None = None
    exclude_auto_sent: bool = False
    terms_and_conditions: str | None = None
    memo: str | None = None
    billing_notes: str | None = None
    # Days from invoice date. Terms vary per customer (confirmed
    # 2026-09-10); null means not yet agreed, and invoices carry no due
    # date until they are.
    payment_terms_days: int | None = Field(default=None, ge=0)
    # Role flags (2026-09-12): a record can be a customer, a supplier, or
    # both -- see CompanyIndividual model docstring. is_customer defaults True
    # since that's the page's usual purpose; tick is_supplier to also
    # make this record selectable on Purchase Order / AP.
    is_customer: bool = True
    is_supplier: bool = False
    # PDPA (2026-09-12): when all data relating to this record must be
    # archived. Optional -- not every record has an agreed expiry yet.
    # PDPA consent itself is NOT set here: it is recorded (with a
    # server-stamped date/time) via the dedicated POST .../pdpa-consent
    # endpoint, never as a plain field a client can set silently.
    data_expiry_date: date | None = None


class CompanyIndividualUpdate(BaseModel):
    customer_type: CompanyIndividualType | None = None
    name: str | None = None
    customer_group_id: uuid.UUID | None = None
    legacy_customer_code: str | None = None
    contact_person: str | None = None
    uen: str | None = None
    gst_registration_no: str | None = None
    billing_email: str | None = None
    phone: str | None = None
    mobile: str | None = None
    website: str | None = None
    address_line1: str | None = None
    address_line2: str | None = None
    address_city: str | None = None
    address_state: str | None = None
    address_postal_code: str | None = None
    address_country: str | None = None
    tags: str | None = None
    industry_code: str | None = None
    exclude_auto_sent: bool | None = None
    terms_and_conditions: str | None = None
    memo: str | None = None
    billing_notes: str | None = None
    payment_terms_days: int | None = Field(default=None, ge=0)
    is_customer: bool | None = None
    is_supplier: bool | None = None
    data_expiry_date: date | None = None


class CompanyIndividualOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    customer_type: CompanyIndividualType
    name: str
    customer_group_id: uuid.UUID | None
    legacy_customer_code: str | None
    contact_person: str | None
    uen: str | None
    gst_registration_no: str | None
    billing_email: str | None
    phone: str | None
    mobile: str | None
    website: str | None
    address_line1: str | None
    address_line2: str | None
    address_city: str | None
    address_state: str | None
    address_postal_code: str | None
    address_country: str | None
    tags: str | None
    industry_code: str | None
    exclude_auto_sent: bool
    terms_and_conditions: str | None
    memo: str | None
    billing_notes: str | None
    payment_terms_days: int | None
    is_customer: bool
    is_supplier: bool
    # PDPA (2026-09-12).
    pdpa_consent_given: bool
    pdpa_consent_at: datetime | None
    # The uploaded signed agreement itself ("need to be able to see the
    # signed agreement") -- a data URI, image or PDF; null if none has
    # been uploaded yet. See PdpaAgreementDocumentUpdate.
    pdpa_agreement_document: str | None
    data_expiry_date: date | None
    is_archived: bool
    archived_at: datetime | None
    is_active: bool
    created_at: datetime


class PdpaConsentUpdate(BaseModel):
    """Ticks/unticks the PDPA-agreement-esigned checkbox on a Company/
    Individual file. The date/time is never taken from the client --
    the server stamps `pdpa_consent_at` itself the moment this is
    called, so it is a reliable record of when consent was actually
    filed (see app/routers/company_individuals.py)."""

    given: bool


class PdpaAgreementDocumentUpdate(BaseModel):
    """Uploads (or, with `document=None`, removes) the scanned/
    photographed/PDF signed PDPA Agreement itself. Validated server-side
    (content type + size cap) in app/routers/company_individuals.py,
    same pattern as Company.logo / User.photo."""

    document: str | None


class ContactCreate(BaseModel):
    name: str
    email: str | None = None
    phone: str | None = None
    direct_line: str | None = None


class ContactUpdate(BaseModel):
    name: str | None = None
    email: str | None = None
    phone: str | None = None
    direct_line: str | None = None


class ContactOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    customer_id: uuid.UUID
    name: str
    email: str | None
    phone: str | None
    direct_line: str | None
    is_active: bool


class BranchCreate(BaseModel):
    branch_name: str
    branch_code: str | None = None
    address_line1: str | None = None
    address_line2: str | None = None
    address_city: str | None = None
    address_state: str | None = None
    address_postal_code: str | None = None
    address_country: str | None = None
    phone: str | None = None


class BranchUpdate(BaseModel):
    branch_name: str | None = None
    branch_code: str | None = None
    address_line1: str | None = None
    address_line2: str | None = None
    address_city: str | None = None
    address_state: str | None = None
    address_postal_code: str | None = None
    address_country: str | None = None
    phone: str | None = None


class BranchOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    customer_id: uuid.UUID
    branch_name: str
    branch_code: str | None
    address_line1: str | None
    address_line2: str | None
    address_city: str | None
    address_state: str | None
    address_postal_code: str | None
    address_country: str | None
    phone: str | None
    is_active: bool


class CompanyIndividualRelationshipCreate(BaseModel):
    # Exactly one of these two (validated in the router) -- company-
    # level and individual-level relationships both use to_customer_id
    # (the level follows from that CompanyIndividual's own customer_type);
    # to_contact_id is the company-contact level.
    to_customer_id: uuid.UUID | None = None
    to_contact_id: uuid.UUID | None = None
    relationship_type: str = Field(min_length=1, max_length=100)
    note: str | None = None


class CompanyIndividualRelationshipOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    from_customer_id: uuid.UUID
    to_customer_id: uuid.UUID | None
    to_customer_name: str | None
    to_customer_type: CompanyIndividualType | None
    to_contact_id: uuid.UUID | None
    to_contact_name: str | None
    to_contact_customer_id: uuid.UUID | None
    to_contact_customer_name: str | None
    relationship_type: str
    note: str | None
    is_active: bool
    created_at: datetime


# ---- Contracts ----
class ContractCreate(BaseModel):
    customer_id: uuid.UUID
    contract_kind: ContractKind = ContractKind.SERVICE_SUPPORT
    # Must be >= 10 per SRV-002/SRV-012 for a SERVICE_SUPPORT contract;
    # ignored (forced to 0) for ANNUAL/AD_HOC, which have no hours.
    contracted_hours: float = Field(default=0, ge=0)
    # Ignored (forced to 0) for AD_HOC, which has no upfront value.
    contract_value_sgd: float = Field(default=0, ge=0)
    start_date: date
    # Required (> 0) when contract_kind is AD_HOC; ignored otherwise.
    hourly_rate_sgd: float | None = Field(default=None, gt=0)
    sales_staff_id: uuid.UUID | None = None
    product_ids: list[uuid.UUID] = Field(default_factory=list)


class ContractProductOut(BaseModel):
    product_id: uuid.UUID
    product_name: str
    # License tracking (2026-09-12): set only for a covered product that
    # is a licensed software item -- see app/models/contracts.py.
    license_type: LicenseDeploymentType | None = None
    number_of_licenses: int | None = None


class ContractProductLicenseUpdate(BaseModel):
    license_type: LicenseDeploymentType | None = None
    number_of_licenses: int | None = Field(default=None, ge=1)


class ContractOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    contract_number: str
    customer_id: uuid.UUID
    status: ContractStatus
    contract_kind: ContractKind
    contracted_hours: float
    consumed_hours: float
    remaining_hours: float
    contract_value_sgd: float
    hourly_rate_sgd: float | None
    sales_staff_id: uuid.UUID | None
    start_date: date
    end_date: date
    renewed_from_contract_id: uuid.UUID | None
    products: list[ContractProductOut] = Field(default_factory=list)

    @classmethod
    def from_model(cls, contract) -> "ContractOut":
        return cls(
            id=contract.id,
            contract_number=contract.contract_number,
            customer_id=contract.customer_id,
            status=contract.status,
            contract_kind=contract.contract_kind,
            contracted_hours=contract.contracted_minutes / 60,
            consumed_hours=contract.consumed_minutes / 60,
            remaining_hours=contract.remaining_minutes / 60,
            contract_value_sgd=float(contract.contract_value_sgd),
            hourly_rate_sgd=float(contract.hourly_rate_sgd) if contract.hourly_rate_sgd is not None else None,
            sales_staff_id=contract.sales_staff_id,
            start_date=contract.start_date,
            end_date=contract.end_date,
            renewed_from_contract_id=contract.renewed_from_contract_id,
            products=[
                ContractProductOut(
                    product_id=cp.product_id,
                    product_name=cp.product.name,
                    license_type=cp.license_type,
                    number_of_licenses=cp.number_of_licenses,
                )
                for cp in contract.products
            ],
        )


class ContractRenewRequest(BaseModel):
    contracted_hours: float = Field(ge=0)
    contract_value_sgd: float = Field(ge=0)
    force_start_date: date | None = None
    hourly_rate_sgd: float | None = Field(default=None, gt=0)


class ContractUpdate(BaseModel):
    """Admin fields adjustable after creation without a renewal --
    who owns the contract commercially and what it covers."""

    sales_staff_id: uuid.UUID | None = None
    product_ids: list[uuid.UUID] | None = None


# ---- Job Orders (formerly "Tickets") ----
class JobOrderCreate(BaseModel):
    customer_id: uuid.UUID
    contract_id: uuid.UUID
    subject: str
    job_order_type: JobOrderType = JobOrderType.SUPPORT
    priority: JobOrderPriority = JobOrderPriority.NORMAL
    # Manual, optional -- set by Sales/Coordinator after discussion with
    # Support. Confirmed 2026-09-10: not derived from priority.
    due_date: date | None = None
    # "Option to also tick Job Order as Urgent" -- confirmed 2026-09-11.
    is_urgent: bool = False


class JobOrderAssign(BaseModel):
    assigned_to_user_id: uuid.UUID


class JobOrderSetDueDate(BaseModel):
    due_date: date | None = None


class JobOrderSetUrgent(BaseModel):
    is_urgent: bool


class JobOrderVoid(BaseModel):
    reason: str = Field(min_length=1, max_length=500)


class ProjectMilestoneOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    job_order_id: uuid.UUID
    milestone_type: MilestoneType
    label: str
    sort_order: int
    planned_start: date | None
    planned_end: date | None
    actual_start: date | None
    actual_end: date | None
    assigned_user_id: uuid.UUID | None
    status: MilestoneStatus
    notes: str | None
    created_at: datetime


class ProjectMilestoneCreate(BaseModel):
    milestone_type: MilestoneType
    label: str
    sort_order: int = 0
    planned_start: date | None = None
    planned_end: date | None = None
    assigned_user_id: uuid.UUID | None = None
    notes: str | None = None


class ProjectMilestoneUpdate(BaseModel):
    label: str | None = None
    sort_order: int | None = None
    planned_start: date | None = None
    planned_end: date | None = None
    actual_start: date | None = None
    actual_end: date | None = None
    assigned_user_id: uuid.UUID | None = None
    status: MilestoneStatus | None = None
    notes: str | None = None


class BudgetOverrunStatus(BaseModel):
    """Computed budget overrun info returned alongside the Job Order."""
    is_over_hours: bool = False
    is_over_cost: bool = False
    consumed_minutes: int = 0
    contracted_minutes: int = 0
    consumed_cost_sgd: float = 0
    contract_value_sgd: float = 0


class JobOrderOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    job_order_number: str
    customer_id: uuid.UUID
    contract_id: uuid.UUID | None
    subject: str
    job_order_type: JobOrderType
    priority: JobOrderPriority
    status: JobOrderStatus
    is_urgent: bool
    assigned_to_user_id: uuid.UUID | None
    due_date: date | None
    void_reason: str | None
    budget_overrun_approved: bool = False
    budget_overrun_approved_by: uuid.UUID | None = None
    budget_overrun_approved_at: datetime | None = None
    created_at: datetime
    closed_at: datetime | None
    milestones: list[ProjectMilestoneOut] = []
    budget_overrun: BudgetOverrunStatus | None = None


# ---- Service Records (formerly "Timesheets") ----
class ServiceRecordCreate(BaseModel):
    job_order_id: uuid.UUID
    employee_user_id: uuid.UUID
    work_date: date
    raw_minutes: int = Field(gt=0)
    # Confirmed 2026-09-11: set by the submitter -- does this session
    # finish the job, or will there be another visit? Drives Job Order
    # auto-close.
    completion_status: ServiceRecordCompletion = ServiceRecordCompletion.UNCOMPLETED
    # Confirmed 2026-09-11: manual (no office-hours/holiday calendar
    # exists to derive this from) -- feeds the suggested deduction
    # multiplier only.
    is_after_hours: bool = False
    # Work description (2026-09-12): optional free text, spellchecked in
    # the browser as it's typed -- see ServiceRecord.work_description.
    work_description: str | None = None


class ServiceRecordApprove(BaseModel):
    # Confirmed 2026-09-11: the approver keys this in herself; the
    # approval form prefills a suggestion but never enforces it.
    deducted_minutes: int = Field(gt=0)


class ServiceRecordOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    service_record_number: str
    job_order_id: uuid.UUID
    employee_user_id: uuid.UUID
    work_date: date
    raw_minutes: int
    rounded_minutes: int
    deducted_minutes: int | None
    status: ServiceRecordStatus
    outcome: ServiceRecordOutcome
    completion_status: ServiceRecordCompletion
    is_after_hours: bool
    is_late: bool
    work_description: str | None = None


class PendingServiceRecordOut(BaseModel):
    """Enriched row for the Service Record Approval page -- joins in
    what the approver needs to see (job order, urgency, a suggested
    deduction) without her having to look each up separately."""

    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    service_record_number: str
    job_order_id: uuid.UUID
    job_order_number: str
    job_order_subject: str
    is_urgent: bool
    employee_user_id: uuid.UUID
    employee_name: str
    work_date: date
    raw_minutes: int
    rounded_minutes: int
    completion_status: ServiceRecordCompletion
    is_after_hours: bool
    suggested_deducted_minutes: int
    contract_remaining_minutes: int | None
    is_late: bool


# ---- Excess usage ----
class ExcessUsageDecision(BaseModel):
    treatment: ExcessTreatment
    reason: str = Field(min_length=1)


class ExcessUsageOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    contract_id: uuid.UUID
    service_record_id: uuid.UUID
    excess_hours: float
    treatment: ExcessTreatment | None
    reason: str | None
    decided_by_user_id: uuid.UUID | None
    invoiced: bool

    @classmethod
    def from_model(cls, record) -> "ExcessUsageOut":
        return cls(
            id=record.id,
            contract_id=record.contract_id,
            service_record_id=record.service_record_id,
            excess_hours=record.excess_minutes / 60,
            treatment=record.treatment,
            reason=record.reason,
            decided_by_user_id=record.decided_by_user_id,
            invoiced=record.invoiced,
        )


# ---- Billing ----
class InvoiceOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    invoice_number: str
    customer_id: uuid.UUID
    contract_id: uuid.UUID | None
    invoice_type: str
    description: str
    # Net of GST -- this is the revenue figure. GST collected is a
    # liability owed to IRAS, not income.
    amount_sgd: float
    tax_code: str
    gst_rate: float
    gst_amount_sgd: float
    total_amount_sgd: float
    amount_paid_sgd: float
    outstanding_sgd: float
    due_date: date | None
    status: str
    is_disputed: bool
    dispute_note: str | None
    issued_at: datetime
    # GL posting (ACC-001); stamped by posting.decorate.
    gl_status: str = "not_posted"
    gl_voucher_number: str | None = None


# ---- Accounts Receivable ----
class PaymentAllocationOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    invoice_id: uuid.UUID
    invoice_number: str | None = None
    amount_sgd: float


class PaymentOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    voucher_number: str
    customer_id: uuid.UUID
    payment_date: date
    amount_sgd: float
    allocated_sgd: float
    unallocated_sgd: float
    method: str
    reference: str | None
    notes: str | None
    allocations: list[PaymentAllocationOut] = []
    # GL posting + Bank step (ACC-001..004); stamped by posting.decorate.
    bank_account_id: uuid.UUID | None = None
    gl_status: str = "not_posted"
    gl_voucher_number: str | None = None
    bank_status: str = "not_banked"
    bank_transaction_number: str | None = None

    @classmethod
    def from_model(cls, payment, invoice_numbers: dict | None = None) -> "PaymentOut":
        numbers = invoice_numbers or {}
        return cls(
            id=payment.id,
            voucher_number=payment.voucher_number,
            customer_id=payment.customer_id,
            payment_date=payment.payment_date,
            amount_sgd=float(payment.amount_sgd),
            allocated_sgd=float(payment.allocated_sgd),
            unallocated_sgd=float(payment.unallocated_sgd),
            method=payment.method.value,
            reference=payment.reference,
            notes=payment.notes,
            allocations=[
                PaymentAllocationOut(
                    id=a.id,
                    invoice_id=a.invoice_id,
                    invoice_number=numbers.get(a.invoice_id),
                    amount_sgd=float(a.amount_sgd),
                )
                for a in payment.allocations
            ],
        )


class PaymentAllocationEntry(BaseModel):
    invoice_id: uuid.UUID
    amount_sgd: float = Field(gt=0)


class PaymentCreate(BaseModel):
    customer_id: uuid.UUID
    payment_date: date
    amount_sgd: float = Field(gt=0)
    method: str = "bank_transfer"
    reference: str | None = None
    notes: str | None = None
    # ACC-001: which bank account the money landed in. Required on every
    # new receipt (the DB column is nullable only for the back-fill).
    bank_account_id: uuid.UUID
    # AR-001: allocation is manual, so it is optional here -- a receipt
    # can be recorded first and allocated later.
    allocations: list[PaymentAllocationEntry] = []


class AllocateRequest(BaseModel):
    allocations: list[PaymentAllocationEntry]


class InvoiceWriteOffRequest(BaseModel):
    reason: str = Field(min_length=1)


class InvoiceDisputeRequest(BaseModel):
    # AR-003: flags the dispute for Finance; collections continue.
    is_disputed: bool
    note: str | None = None


class AgingRow(BaseModel):
    customer_id: uuid.UUID
    customer_name: str
    current: float
    days_1_30: float
    days_31_60: float
    days_61_90: float
    over_90: float
    total: float


class AgingReport(BaseModel):
    as_at: date
    rows: list[AgingRow]
    current: float
    days_1_30: float
    days_31_60: float
    days_61_90: float
    over_90: float
    total: float


class StatementLine(BaseModel):
    invoice_id: uuid.UUID
    invoice_number: str
    description: str
    issued_on: date
    due_date: date | None
    total_amount_sgd: float
    amount_paid_sgd: float
    outstanding_sgd: float
    status: str
    is_disputed: bool
    days_overdue: int


class CompanyIndividualStatement(BaseModel):
    customer_id: uuid.UUID
    customer_name: str
    as_at: date
    payment_terms_days: int | None
    lines: list[StatementLine]
    total_outstanding_sgd: float
    unallocated_credit_sgd: float


# ---- Chart of Accounts ----
class AccountOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    code: str
    name: str
    account_type: AccountType
    description: str | None
    is_active: bool


class AccountCreate(BaseModel):
    code: str = Field(min_length=1, max_length=20)
    name: str = Field(min_length=1)
    account_type: AccountType
    description: str | None = None


class AccountUpdate(BaseModel):
    code: str | None = None
    name: str | None = None
    account_type: AccountType | None = None
    description: str | None = None
    is_active: bool | None = None


# ---- Reference Monitor (GL sub-codes under one Chart of Accounts row) ----
class ReferenceCodeOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    account_id: uuid.UUID
    account_code: str | None = None
    account_name: str | None = None
    code: str
    name: str
    is_active: bool
    created_at: datetime


class ReferenceCodeCreate(BaseModel):
    account_id: uuid.UUID
    code: str = Field(min_length=1, max_length=50)
    name: str = Field(min_length=1)


class ReferenceCodeUpdate(BaseModel):
    account_id: uuid.UUID | None = None
    code: str | None = None
    name: str | None = None
    is_active: bool | None = None


# ---- General Ledger / vouchers ----
class JournalLineOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    account_id: uuid.UUID
    account_code: str | None = None
    account_name: str | None = None
    debit_sgd: float
    credit_sgd: float
    description: str | None


class JournalEntryOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    voucher_number: str
    voucher_type: VoucherType
    entry_date: date
    narration: str
    status: JournalStatus
    total_debit: float
    total_credit: float
    is_balanced: bool
    reverses_entry_id: uuid.UUID | None
    lines: list[JournalLineOut] = []

    @classmethod
    def from_model(cls, entry) -> "JournalEntryOut":
        return cls(
            id=entry.id,
            voucher_number=entry.voucher_number,
            voucher_type=entry.voucher_type,
            entry_date=entry.entry_date,
            narration=entry.narration,
            status=entry.status,
            total_debit=float(entry.total_debit),
            total_credit=float(entry.total_credit),
            is_balanced=entry.is_balanced,
            reverses_entry_id=entry.reverses_entry_id,
            lines=[
                JournalLineOut(
                    id=l.id,
                    account_id=l.account_id,
                    account_code=l.account.code if l.account else None,
                    account_name=l.account.name if l.account else None,
                    debit_sgd=float(l.debit_sgd),
                    credit_sgd=float(l.credit_sgd),
                    description=l.description,
                )
                for l in entry.lines
            ],
        )


class JournalLineCreate(BaseModel):
    account_id: uuid.UUID
    debit_sgd: float = Field(default=0, ge=0)
    credit_sgd: float = Field(default=0, ge=0)
    description: str | None = None


class JournalEntryCreate(BaseModel):
    entry_date: date
    narration: str = Field(min_length=1)
    lines: list[JournalLineCreate]
    # Post immediately rather than leaving it as a draft.
    post: bool = False


class ReverseRequest(BaseModel):
    reason: str = Field(min_length=1)


class TrialBalanceRow(BaseModel):
    account_id: uuid.UUID
    code: str
    name: str
    account_type: AccountType
    debit_sgd: float
    credit_sgd: float
    balance_sgd: float


class TrialBalance(BaseModel):
    as_at: date | None
    rows: list[TrialBalanceRow]
    total_debit: float
    total_credit: float
    is_balanced: bool


class GLTransactionRow(BaseModel):
    """One posted journal line touching a given account."""
    line_id: uuid.UUID
    entry_id: uuid.UUID
    voucher_number: str
    voucher_type: str
    entry_date: date
    narration: str
    line_description: str | None
    debit_sgd: float
    credit_sgd: float
    balance_sgd: float


class GLTransactions(BaseModel):
    """Account-level GL transaction ledger."""
    account_id: uuid.UUID
    account_code: str
    account_name: str
    account_type: str
    date_from: date | None
    date_to: date | None
    rows: list[GLTransactionRow]
    total_debit: float
    total_credit: float
    closing_balance: float


# ---- Accounts Payable ----
# Supplier CRUD schemas were removed 2026-09-12: a supplier is a CompanyIndividual
# (Company/Individual) record flagged is_supplier=True -- see
# CompanyIndividualCreate/CompanyIndividualUpdate/CompanyIndividualOut above, and
# app/models/payables.py's module docstring.
class PurchaseOrderOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    po_number: str
    supplier_id: uuid.UUID
    order_date: date
    description: str
    amount_sgd: float
    gst_amount_sgd: float
    total_amount_sgd: float
    status: PurchaseOrderStatus
    # Set once "Import to AP" has created the matching bill -- lets the UI
    # show "Imported -> BILL-0007" and hide the button rather than allow a
    # second, duplicate bill for the same PO.
    imported_bill_id: uuid.UUID | None = None
    imported_bill_number: str | None = None

    @classmethod
    def from_model(cls, po: "PurchaseOrder") -> "PurchaseOrderOut":
        bill = po.bills[0] if po.bills else None
        return cls(
            id=po.id,
            po_number=po.po_number,
            supplier_id=po.supplier_id,
            order_date=po.order_date,
            description=po.description,
            amount_sgd=float(po.amount_sgd),
            gst_amount_sgd=float(po.gst_amount_sgd),
            total_amount_sgd=float(po.total_amount_sgd),
            status=po.status,
            imported_bill_id=bill.id if bill else None,
            imported_bill_number=bill.bill_number if bill else None,
        )


class PurchaseOrderCreate(BaseModel):
    supplier_id: uuid.UUID
    order_date: date
    description: str = Field(min_length=1)
    amount_sgd: float = Field(gt=0)


class SupplierInvoiceOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    bill_number: str
    supplier_invoice_no: str | None
    supplier_id: uuid.UUID
    purchase_order_id: uuid.UUID | None
    invoice_date: date
    due_date: date | None
    description: str
    amount_sgd: float
    gst_amount_sgd: float
    total_amount_sgd: float
    amount_paid_sgd: float
    outstanding_sgd: float
    match_status: BillMatchStatus
    match_note: str | None
    status: BillStatus
    # GL posting (ACC-001); stamped by posting.decorate.
    expense_account_id: uuid.UUID | None = None
    gl_status: str = "not_posted"
    gl_voucher_number: str | None = None


class SupplierInvoiceCreate(BaseModel):
    supplier_id: uuid.UUID
    purchase_order_id: uuid.UUID | None = None
    supplier_invoice_no: str | None = None
    invoice_date: date
    description: str = Field(min_length=1)
    amount_sgd: float = Field(gt=0)
    gst_amount_sgd: float = Field(default=0, ge=0)
    # Optional expense account for the GL posting (gl-posting-design.md
    # §4.2); the posting service defaults to 5000 Cost of services.
    expense_account_id: uuid.UUID | None = None


class SupplierPaymentAllocationOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    supplier_invoice_id: uuid.UUID
    bill_number: str | None = None
    amount_sgd: float


class SupplierPaymentOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    voucher_number: str
    supplier_id: uuid.UUID
    payment_date: date
    amount_sgd: float
    allocated_sgd: float
    unallocated_sgd: float
    method: str
    reference: str | None
    allocations: list[SupplierPaymentAllocationOut] = []
    # GL posting + Bank step (ACC-001..004); stamped by posting.decorate.
    bank_account_id: uuid.UUID | None = None
    gl_status: str = "not_posted"
    gl_voucher_number: str | None = None
    bank_status: str = "not_banked"
    bank_transaction_number: str | None = None

    @classmethod
    def from_model(cls, payment, bill_numbers: dict | None = None) -> "SupplierPaymentOut":
        numbers = bill_numbers or {}
        return cls(
            id=payment.id,
            voucher_number=payment.voucher_number,
            supplier_id=payment.supplier_id,
            payment_date=payment.payment_date,
            amount_sgd=float(payment.amount_sgd),
            allocated_sgd=float(payment.allocated_sgd),
            unallocated_sgd=float(payment.unallocated_sgd),
            method=payment.method,
            reference=payment.reference,
            allocations=[
                SupplierPaymentAllocationOut(
                    id=a.id,
                    supplier_invoice_id=a.supplier_invoice_id,
                    bill_number=numbers.get(a.supplier_invoice_id),
                    amount_sgd=float(a.amount_sgd),
                )
                for a in payment.allocations
            ],
        )


class SupplierPaymentAllocationEntry(BaseModel):
    supplier_invoice_id: uuid.UUID
    amount_sgd: float = Field(gt=0)


class SupplierPaymentCreate(BaseModel):
    supplier_id: uuid.UUID
    payment_date: date
    amount_sgd: float = Field(gt=0)
    method: str = "bank_transfer"
    reference: str | None = None
    notes: str | None = None
    # ACC-001: which bank account the payment went out of. Required.
    bank_account_id: uuid.UUID
    allocations: list[SupplierPaymentAllocationEntry] = []


class SupplierAllocateRequest(BaseModel):
    allocations: list[SupplierPaymentAllocationEntry]


class APAgingRow(BaseModel):
    supplier_id: uuid.UUID
    supplier_name: str
    current: float
    days_1_30: float
    days_31_60: float
    days_61_90: float
    over_90: float
    total: float


class APAgingReport(BaseModel):
    as_at: date
    rows: list[APAgingRow]
    total: float


# ---- Module Control / licensing ----
class ModuleOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    key: str
    name: str
    description: str | None
    is_built: bool
    enabled: bool
    license_type: LicenseType


class ModuleToggleRequest(BaseModel):
    enabled: bool
    license_type: LicenseType | None = None
    notes: str | None = None


# ---- Dashboard ----
class DashboardSummary(BaseModel):
    active_contracts: int
    contracts_expiring_soon: int  # SRV-014: within 30 days of expiry
    total_contracted_hours: float
    total_consumed_hours: float
    total_remaining_hours: float
    excess_awaiting_review: int
    open_job_orders: int
    missing_service_records: int  # SRV-015: submitted more than 3 business days after the work date
    invoices_total_sgd: float
    invoices_count: int
    # Financial summary -- same figures as the AR/AP aging reports and the
    # GL trial balance, just totalled for an at-a-glance dashboard tile
    # (see app/services/reports.py, which both this and the Accounting
    # Reports screen read from).
    ar_outstanding_sgd: float
    ar_overdue_sgd: float  # outstanding minus the "current" (not yet due) bucket
    ap_outstanding_sgd: float
    ap_overdue_sgd: float
    gl_is_balanced: bool


# ---- Product / Service Catalog ----
class ProductCreate(BaseModel):
    product_type: ProductType = ProductType.service
    name: str
    internal_reference: str | None = None
    product_category: str | None = None
    tags: str | None = None
    sales_price_sgd: float = Field(default=0, ge=0)
    cost_sgd: float | None = Field(default=None, ge=0)
    unit_of_measure: str | None = None
    tax_code: str = "SR"
    default_reference_code_id: uuid.UUID | None = None
    is_stock: bool = False


class ProductUpdate(BaseModel):
    product_type: ProductType | None = None
    name: str | None = None
    internal_reference: str | None = None
    product_category: str | None = None
    tags: str | None = None
    sales_price_sgd: float | None = Field(default=None, ge=0)
    cost_sgd: float | None = Field(default=None, ge=0)
    unit_of_measure: str | None = None
    tax_code: str | None = None
    default_reference_code_id: uuid.UUID | None = None
    is_stock: bool | None = None
    is_active: bool | None = None


class ProductOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    product_type: ProductType
    name: str
    internal_reference: str | None
    product_category: str | None
    tags: str | None
    sales_price_sgd: float
    cost_sgd: float | None
    unit_of_measure: str | None
    tax_code: str
    default_reference_code_id: uuid.UUID | None
    is_stock: bool
    is_active: bool
    created_at: datetime


# ---- Sales Quotation ----
class QuotationLineCreate(BaseModel):
    product_id: uuid.UUID | None = None
    description: str
    unit_of_measure: str | None = None
    quantity: float = Field(gt=0)
    unit_price_sgd: float = Field(ge=0)
    # Overrides the chosen product's default_reference_code_id when set;
    # left unset, create_quotation fills it from that default.
    reference_code_id: uuid.UUID | None = None
    # Costing (2026-09-12): overrides the chosen product's Product.cost_sgd
    # when set; left unset, create_quotation fills it from that default.
    # A non-product line has no default to fall back on -- this is its
    # only source of cost, so leave it unset only when the line truly has
    # none (e.g. a discount line).
    cost_sgd: float | None = None


class QuotationLineOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    product_id: uuid.UUID | None
    description: str
    unit_of_measure: str | None
    quantity: float
    unit_price_sgd: float
    line_total_sgd: float
    reference_code_id: uuid.UUID | None
    cost_sgd: float | None


class QuotationCreate(BaseModel):
    customer_id: uuid.UUID
    quotation_date: date
    valid_until: date | None = None
    notes: str | None = None
    lines: list[QuotationLineCreate] = Field(default_factory=list)


class QuotationOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    quotation_number: str
    customer_id: uuid.UUID
    quotation_date: date
    valid_until: date | None
    status: QuotationStatus
    notes: str | None
    amount_sgd: float
    tax_code: str
    gst_rate: float
    gst_amount_sgd: float
    total_amount_sgd: float
    converted_contract_id: uuid.UUID | None
    converted_annual_contract_id: uuid.UUID | None
    created_at: datetime
    lines: list[QuotationLineOut]


class QuotationActionResult(BaseModel):
    quotation: QuotationOut
    message: str


# ---- Support Monitoring ----
class StaffMonitoringOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    user_id: uuid.UUID
    full_name: str
    photo: str | None = None
    open_job_orders: int
    overdue_job_orders: int
    due_soon_job_orders: int
    pending_service_records: int
    untested_software_tasks: int
    cm_svc_records_month: int
    cm_svc_records_today: int
    cm_svc_hours_month: float
    cm_svc_hours_today: float
    avg_daily_contract_hours: float


class MonitoringSummaryOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    total_job_orders: int
    total_open_job_orders: int
    total_overdue_job_orders: int
    unassigned_job_orders: int
    total_pending_service_records: int
    total_untested_software_tasks: int


class SupportMonitoringOut(BaseModel):
    as_at: date
    summary: MonitoringSummaryOut
    staff: list[StaffMonitoringOut]
    unassigned: StaffMonitoringOut


# ---- Software Task ----
class SoftwareTaskCreate(BaseModel):
    title: str
    description: str | None = None
    modules_affected: str | None = None
    assigned_programmer_id: uuid.UUID | None = None
    programming_finish_date: date | None = None
    programming_hours: float | None = Field(default=None, ge=0)
    tester_user_id: uuid.UUID | None = None


class SoftwareTaskUpdate(BaseModel):
    title: str | None = None
    description: str | None = None
    modules_affected: str | None = None
    assigned_programmer_id: uuid.UUID | None = None
    programming_finish_date: date | None = None
    programming_hours: float | None = Field(default=None, ge=0)
    tester_user_id: uuid.UUID | None = None


class SoftwareTaskOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    title: str
    description: str | None
    modules_affected: str | None
    assigned_programmer_id: uuid.UUID | None
    programming_finish_date: date | None
    programming_hours: float | None
    tester_user_id: uuid.UUID | None
    is_tested: bool
    tested_at: datetime | None
    created_at: datetime


# ---- Incident Module (2026-09-12, docs/open-business-decisions.md #36) ----
class IncidentCreate(BaseModel):
    customer_id: uuid.UUID | None = None
    source: IncidentSource = IncidentSource.PHONE
    subject: str = Field(min_length=1, max_length=255)
    description: str | None = None
    sender_name: str | None = None
    sender_email: str | None = None
    sender_phone: str | None = None


class IncidentOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    incident_number: str
    customer_id: uuid.UUID | None
    source: IncidentSource
    subject: str
    description: str | None
    sender_name: str | None
    sender_email: str | None
    sender_phone: str | None
    status: IncidentStatus
    assigned_to_user_id: uuid.UUID | None
    converted_quotation_id: uuid.UUID | None
    converted_job_order_id: uuid.UUID | None
    converted_software_task_id: uuid.UUID | None
    close_reason: str | None
    closed_at: datetime | None
    created_at: datetime


class IncidentSetCustomer(BaseModel):
    customer_id: uuid.UUID


class IncidentCallback(BaseModel):
    assigned_to_user_id: uuid.UUID


class IncidentClose(BaseModel):
    reason: str = Field(min_length=1, max_length=500)


class IncidentConvertToQuotation(BaseModel):
    quotation_date: date


class IncidentConvertToJobOrder(BaseModel):
    contract_id: uuid.UUID
    priority: JobOrderPriority = JobOrderPriority.NORMAL


class IncidentConvertToSoftwareTask(BaseModel):
    assigned_programmer_id: uuid.UUID | None = None


# The Outlook Add-in acts directly on an email, without an Incident
# already existing -- see outlook-addin/README.md. "Convert to Job
# Order" tries to resolve a customer from the sender's email and find a
# valid (ACTIVE/EXCEEDED) contract; confirmed 2026-09-12: if either is
# missing, it falls back to creating a plain Incident instead of erroring.
class IncidentFromEmail(BaseModel):
    sender_name: str | None = None
    sender_email: str
    subject: str = Field(min_length=1, max_length=255)
    body: str | None = None


class IncidentFromEmailResult(BaseModel):
    incident: IncidentOut
    # True only when a Job Order was actually created (the "Convert to
    # Job Order" button's happy path); false means it fell back to a
    # plain Incident -- `fallback_reason` says why.
    job_order_created: bool
    fallback_reason: str | None = None


# ---- Setup Lists (Nationality / Country / State / Area Code / Currency) ----
class SetupListItemOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    list_type: SetupListType
    code: str
    name: str
    parent_code: str | None
    sort_order: int
    is_active: bool


class SetupListItemCreate(BaseModel):
    list_type: SetupListType
    code: str = Field(min_length=1, max_length=20)
    name: str = Field(min_length=1, max_length=150)
    parent_code: str | None = None
    sort_order: int = 0


class SetupListItemUpdate(BaseModel):
    code: str | None = None
    name: str | None = None
    parent_code: str | None = None
    sort_order: int | None = None
    is_active: bool | None = None


# ---- GL Types ----
class GLTypeOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    code: str
    name: str
    account_type: AccountType
    is_active: bool


class GLTypeCreate(BaseModel):
    code: str = Field(min_length=1, max_length=20)
    name: str = Field(min_length=1, max_length=100)
    account_type: AccountType


class GLTypeUpdate(BaseModel):
    code: str | None = None
    name: str | None = None
    account_type: AccountType | None = None
    is_active: bool | None = None


# ---- Currency Rate Table ----
class CurrencyRateOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    currency_code: str
    rate_to_base: float
    effective_date: date
    is_active: bool


class CurrencyRateCreate(BaseModel):
    currency_code: str = Field(min_length=3, max_length=3)
    rate_to_base: float = Field(gt=0)
    effective_date: date


class CurrencyRateUpdate(BaseModel):
    rate_to_base: float | None = Field(default=None, gt=0)
    is_active: bool | None = None


# ---- Bank Master File ----
class BankAccountOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    bank_name: str
    account_name: str
    account_number: str
    branch: str | None
    swift_code: str | None
    currency_code: str
    gl_account_id: uuid.UUID | None
    # Bank Book (2026-09-12) -- see app/models/treasury.py's module
    # docstring for why this is a separate ledger from the GL.
    opening_balance_sgd: float
    opening_balance_date: date | None
    # Opening balance + every non-voided BankTransaction to date --
    # computed by the router, not a stored column.
    current_balance_sgd: float = 0
    is_active: bool


class BankAccountCreate(BaseModel):
    bank_name: str = Field(min_length=1, max_length=150)
    account_name: str = Field(min_length=1, max_length=150)
    account_number: str = Field(min_length=1, max_length=50)
    branch: str | None = None
    swift_code: str | None = None
    currency_code: str = Field(default="SGD", min_length=3, max_length=3)
    gl_account_id: uuid.UUID | None = None
    opening_balance_sgd: float = 0
    opening_balance_date: date | None = None


class BankAccountUpdate(BaseModel):
    bank_name: str | None = None
    account_name: str | None = None
    account_number: str | None = None
    branch: str | None = None
    swift_code: str | None = None
    currency_code: str | None = None
    gl_account_id: uuid.UUID | None = None
    opening_balance_sgd: float | None = None
    opening_balance_date: date | None = None
    is_active: bool | None = None


# ---- Bank Book: Bank Transactions + Bank Reconciliation (2026-09-12) ----
class BankTransactionOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    bank_account_id: uuid.UUID
    transaction_number: str
    transaction_date: date
    description: str
    reference: str | None
    debit_sgd: float
    credit_sgd: float
    is_reconciled: bool
    reconciled_at: datetime | None
    is_voided: bool
    void_reason: str | None
    voided_at: datetime | None
    created_at: datetime
    # Set when the Bank step created this line from a voucher (ACC-002).
    source_type: str | None = None
    source_id: uuid.UUID | None = None
    # Running balance as at this line -- computed by the router in
    # ledger (date/creation) order, not a stored column.
    running_balance_sgd: float = 0


class BankTransactionCreate(BaseModel):
    transaction_date: date
    description: str = Field(min_length=1, max_length=500)
    reference: str | None = None
    # Exactly one of these must be > 0 -- same rule as a JournalLine.
    debit_sgd: float = Field(default=0, ge=0)
    credit_sgd: float = Field(default=0, ge=0)


class BankTransactionVoid(BaseModel):
    reason: str = Field(min_length=1, max_length=500)


class BankLedgerOut(BaseModel):
    bank_account_id: uuid.UUID
    opening_balance_sgd: float
    opening_balance_date: date | None
    rows: list[BankTransactionOut]
    closing_balance_sgd: float
    reconciled_balance_sgd: float
    unreconciled_count: int


class BankReconciliationCreate(BaseModel):
    statement_date: date
    statement_balance_sgd: float
    note: str | None = None
    # Transactions to mark reconciled as part of saving this session --
    # anything already reconciled is left as-is; nothing here unreconciles
    # a line (untick it individually first if that's really intended).
    reconciled_transaction_ids: list[uuid.UUID] = Field(default_factory=list)


class BankReconciliationOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    bank_account_id: uuid.UUID
    statement_date: date
    statement_balance_sgd: float
    ledger_balance_sgd: float
    difference_sgd: float
    note: str | None
    reconciled_by_user_id: uuid.UUID | None
    reconciled_by_name: str | None = None
    created_at: datetime


# ---- Tax Type (TaxCode maintenance) ----
class TaxCodeOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    code: str
    name: str
    rate_percent: float
    is_active: bool


class TaxCodeCreate(BaseModel):
    code: str = Field(min_length=1, max_length=10)
    name: str = Field(min_length=1, max_length=100)
    rate_percent: float = Field(ge=0, le=100)


class TaxCodeUpdate(BaseModel):
    code: str | None = None
    name: str | None = None
    rate_percent: float | None = Field(default=None, ge=0, le=100)
    is_active: bool | None = None


# ---- Document Control ----
class DocumentSequenceOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    doc_kind: str
    prefix: str
    year: int
    last_number: int
    # What the next document raised in this kind/year will look like,
    # given the current format (default or customized) -- computed
    # server-side so the UI never has to guess the padding/layout.
    next_number: str


class DocumentSequenceUpdate(BaseModel):
    last_number: int = Field(ge=0)
    reason: str = Field(min_length=1)


class DocumentNumberFormatOut(BaseModel):
    doc_kind: str
    prefix: str
    number_length: int
    include_year: bool
    # False when this doc_kind has no override row yet and is showing
    # the built-in default -- lets the UI say "default" vs "custom".
    is_custom: bool
    example: str


class DocumentNumberFormatUpdate(BaseModel):
    # Confirmed 2026-09-11: "customization of the running number
    # formatting and front alphabet" -- uppercase letters/digits only
    # (the "-year-seq" separators are added automatically, not part of
    # what's customizable here).
    prefix: str = Field(min_length=1, max_length=10, pattern=r"^[A-Z0-9]+$")
    number_length: int = Field(ge=1, le=10)
    include_year: bool = True
    reason: str = Field(min_length=1)


# ---- Accounting Periods / Year-End Closing ----
class PeriodLockOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    doc_type: PeriodDocType
    operation: PeriodOperation
    is_locked: bool
    locked_by_user_id: uuid.UUID | None
    locked_at: datetime | None


class AccountingPeriodOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    fiscal_year: int
    name: str
    period_start: date
    period_end: date
    status: PeriodStatus
    closed_at: datetime | None
    locks: list[PeriodLockOut] = []


class AccountingPeriodCreate(BaseModel):
    fiscal_year: int
    name: str = Field(min_length=1, max_length=50)
    period_start: date
    period_end: date


class PeriodLockToggleRequest(BaseModel):
    doc_type: PeriodDocType
    operation: PeriodOperation
    locked: bool


class FiscalYearClosureOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    fiscal_year: int
    retained_earnings_account_id: uuid.UUID
    closing_journal_entry_id: uuid.UUID
    closed_at: datetime


class YearEndClosingRequest(BaseModel):
    fiscal_year: int
    retained_earnings_account_id: uuid.UUID


# ---- GST Return (read-only, doesn't file or post anything) ----
class GSTReturnRow(BaseModel):
    tax_code: str
    net_sgd: float
    tax_sgd: float
    document_count: int


class GSTReturn(BaseModel):
    period_start: date
    period_end: date
    output_rows: list[GSTReturnRow]  # sales -- output tax collected
    input_rows: list[GSTReturnRow]  # purchases -- input tax paid
    total_output_tax_sgd: float
    total_input_tax_sgd: float
    net_gst_payable_sgd: float  # output - input; negative means reclaimable


# ---- Sales GP + Commission (2026-09-12, docs/open-business-decisions.md #32-#34) ----
class SalesGPRow(BaseModel):
    invoice_id: uuid.UUID
    invoice_number: str
    issued_at: datetime
    customer_id: uuid.UUID
    customer_name: str
    revenue_sgd: float
    cost_sgd: float
    gp_sgd: float
    gp_percent: float
    # False when cost_sgd is a stand-in zero, not a real known cost --
    # e.g. an EXCESS_USAGE invoice, or a CONTRACT_ANNUAL invoice whose
    # quotation never had a cost entered on any line.
    has_cost_basis: bool


class SalesGPReport(BaseModel):
    period_start: date
    period_end: date
    rows: list[SalesGPRow]
    total_revenue_sgd: float
    total_cost_sgd: float
    total_gp_sgd: float
    total_gp_percent: float


class CommissionSettingsOut(BaseModel):
    rate_percent: float


class CommissionSettingsUpdate(BaseModel):
    rate_percent: float = Field(ge=0, le=100)


class CommissionRow(BaseModel):
    month: str  # "YYYY-MM"
    sales_staff_id: uuid.UUID | None
    sales_staff_name: str
    commission_sgd: float


class CommissionReport(BaseModel):
    period_start: date
    period_end: date
    rate_percent: float
    rows: list[CommissionRow]
    total_commission_sgd: float


# ---- Commission Payouts (6.3/6.4/6.5, built 2026-09-12) ----


class CommissionPayoutCreate(BaseModel):
    period_month: str = Field(min_length=7, max_length=7, pattern=r"^\d{4}-\d{2}$")


class CommissionPayoutReject(BaseModel):
    reason: str | None = None


class CommissionPayoutMarkPaid(BaseModel):
    paid_date: date
    paid_reference: str | None = None


class CommissionPayoutOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)

    id: uuid.UUID
    company_id: uuid.UUID
    payout_number: str
    payout_type: str
    status: str
    sales_staff_id: uuid.UUID
    period_month: str
    period_start: date
    period_end: date
    amount_sgd: float
    rate_percent: float
    clawback_invoice_id: uuid.UUID | None = None
    clawback_reason: str | None = None
    submitted_by_user_id: uuid.UUID | None = None
    submitted_at: datetime | None = None
    approved_by_user_id: uuid.UUID | None = None
    approved_at: datetime | None = None
    paid_date: date | None = None
    paid_reference: str | None = None
    paid_by_user_id: uuid.UUID | None = None
    notes: str | None = None
    created_at: datetime
    updated_at: datetime


# ---- Ops Dashboard (personal task tracker, confirmed 2026-09-11) ----
class OpsTaskCategoryCreate(BaseModel):
    name: str = Field(min_length=1, max_length=255)
    cadence_label: str | None = None
    # Manager creating a category on someone else's dashboard; defaults
    # to the caller's own when omitted.
    owner_user_id: uuid.UUID | None = None


class OpsTaskCategoryOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    owner_user_id: uuid.UUID
    name: str
    cadence_label: str | None
    sort_order: int


class OpsTaskCreate(BaseModel):
    category_id: uuid.UUID
    title: str = Field(min_length=1, max_length=500)
    status: OpsTaskStatus = OpsTaskStatus.NOT_STARTED
    next_action: str | None = None
    owner_label: str | None = None
    due_label: str | None = None
    follow_up_staff_id: uuid.UUID | None = None
    follow_up_date: date | None = None


class OpsTaskUpdate(BaseModel):
    title: str | None = None
    status: OpsTaskStatus | None = None
    next_action: str | None = None
    owner_label: str | None = None
    due_label: str | None = None
    follow_up_staff_id: uuid.UUID | None = None
    follow_up_date: date | None = None
    clear_follow_up_staff: bool = False
    clear_follow_up_date: bool = False


class OpsTaskOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    category_id: uuid.UUID
    owner_user_id: uuid.UUID
    title: str
    status: OpsTaskStatus
    next_action: str | None
    owner_label: str | None
    due_label: str | None
    follow_up_staff_id: uuid.UUID | None
    follow_up_staff_name: str | None = None
    follow_up_date: date | None
    is_sample: bool


class OpsDashboardCategoryOut(BaseModel):
    category: OpsTaskCategoryOut
    tasks: list[OpsTaskOut]


class OpsRollupJobOrderOut(BaseModel):
    id: uuid.UUID
    job_order_number: str
    subject: str
    status: str
    due_date: date | None


class OpsRollupSoftwareTaskOut(BaseModel):
    id: uuid.UUID
    title: str
    role: str  # "Programmer" or "Tester"
    is_tested: bool


class OpsDashboardOut(BaseModel):
    staff_id: uuid.UUID
    staff_name: str
    can_view_others: bool
    categories: list[OpsDashboardCategoryOut]
    total_tasks: int
    open_count: int
    in_progress_count: int
    blocked_count: int
    done_count: int
    my_job_orders: list[OpsRollupJobOrderOut]
    my_software_tasks: list[OpsRollupSoftwareTaskOut]


# ---- Announcements / ad banner (2026-09-12: "is there a place for me to
# set all these advertisements or latest updates and push publish") --
# see app/models/announcements.py.
class AnnouncementOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    tag: str | None
    text: str
    sort_order: int
    is_active: bool
    created_at: datetime


class AnnouncementCreate(BaseModel):
    tag: str | None = Field(default=None, max_length=30)
    text: str = Field(min_length=1, max_length=500)
    sort_order: int = 0


class AnnouncementUpdate(BaseModel):
    tag: str | None = Field(default=None, max_length=30)
    text: str | None = Field(default=None, min_length=1, max_length=500)
    sort_order: int | None = None
    is_active: bool | None = None


class AdBannerSettingsOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    video_url: str | None


class AdBannerSettingsUpdate(BaseModel):
    video_url: str | None = Field(default=None, max_length=1000)


class PublicAdBanner(BaseModel):
    """Unauthenticated shape read by the Login page and by the app-wide
    ad banner alike (see PromoVideoPanel.tsx) -- the video URL plus only
    the ACTIVE announcements, already ordered. Deliberately the same
    endpoint for both: the content is identical and non-sensitive
    either way, so there is no separate "authenticated" copy of it."""

    video_url: str | None
    items: list[AnnouncementOut]


# ── eDocument Attachments + eSignature ─────────────────────────────


class DocumentAttachmentOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    company_id: uuid.UUID
    entity_type: DocumentEntityType
    entity_id: uuid.UUID
    uploaded_by_user_id: uuid.UUID
    original_filename: str
    content_type: str
    file_size_bytes: int
    description: str | None
    uploaded_at: datetime


class DocumentSignatureCreate(BaseModel):
    entity_type: DocumentEntityType
    entity_id: uuid.UUID
    signer_name: str = Field(min_length=1, max_length=255)
    signature_data_uri: str = Field(min_length=1)
    role_label: str | None = Field(default=None, max_length=100)


class DocumentSignatureOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    company_id: uuid.UUID
    entity_type: DocumentEntityType
    entity_id: uuid.UUID
    signer_user_id: uuid.UUID
    signer_name: str
    role_label: str | None
    signed_at: datetime


# ── eApproval Master ───────────────────────────────────────────────


class ApprovalAuthorityMemberOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    authority_id: uuid.UUID
    user_id: uuid.UUID
    added_at: datetime


class ApprovalRuleOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    authority_id: uuid.UUID
    entity_type: DocumentEntityType
    threshold_amount: float | None
    priority: int
    is_active: bool
    created_at: datetime


class ApprovalAuthorityOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    company_id: uuid.UUID
    name: str
    description: str | None
    mode: ApprovalMode
    bank_account_id: uuid.UUID | None
    is_active: bool
    created_at: datetime
    members: list[ApprovalAuthorityMemberOut] = []
    rules: list[ApprovalRuleOut] = []


class ApprovalAuthorityCreate(BaseModel):
    name: str = Field(min_length=1, max_length=200)
    description: str | None = Field(default=None, max_length=500)
    mode: ApprovalMode = ApprovalMode.ANY_ONE
    bank_account_id: uuid.UUID | None = None


class ApprovalAuthorityUpdate(BaseModel):
    name: str | None = Field(default=None, min_length=1, max_length=200)
    description: str | None = Field(default=None, max_length=500)
    mode: ApprovalMode | None = None
    bank_account_id: uuid.UUID | None = None
    is_active: bool | None = None


class ApprovalAuthorityMemberAdd(BaseModel):
    user_id: uuid.UUID


class ApprovalRuleCreate(BaseModel):
    authority_id: uuid.UUID
    entity_type: DocumentEntityType
    threshold_amount: float | None = None
    priority: int = 0


class ApprovalRuleUpdate(BaseModel):
    entity_type: DocumentEntityType | None = None
    threshold_amount: float | None = None
    priority: int | None = None
    is_active: bool | None = None


class ApprovalDecisionOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    request_id: uuid.UUID
    user_id: uuid.UUID
    decision: ApprovalDecisionValue
    comment: str | None
    decided_at: datetime


class ApprovalRequestOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    company_id: uuid.UUID
    entity_type: DocumentEntityType
    entity_id: uuid.UUID
    rule_id: uuid.UUID
    authority_id: uuid.UUID
    status: ApprovalStatus
    requested_by_user_id: uuid.UUID
    requested_at: datetime
    resolved_at: datetime | None
    decisions: list[ApprovalDecisionOut] = []


class ApprovalSubmitRequest(BaseModel):
    entity_type: DocumentEntityType
    entity_id: uuid.UUID
    amount: float | None = None


class ApprovalDecisionRequest(BaseModel):
    decision: ApprovalDecisionValue
    comment: str | None = Field(default=None, max_length=1000)


# ── Stock / Inventory ────────────────────────────────────────────────

class WarehouseCreate(BaseModel):
    code: str = Field(max_length=20)
    name: str = Field(max_length=200)
    address: str | None = None

class WarehouseOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    company_id: uuid.UUID
    code: str
    name: str
    address: str | None
    is_active: bool
    created_at: datetime
    updated_at: datetime

class StockItemCreate(BaseModel):
    code: str = Field(max_length=50)
    name: str = Field(max_length=255)
    description: str | None = None
    category: str | None = Field(default=None, max_length=100)
    unit_of_measure: str = Field(default="PCS", max_length=30)
    product_id: uuid.UUID | None = None
    reorder_level: int = 0
    # Extended fields
    category_id: uuid.UUID | None = None
    group_id: uuid.UUID | None = None
    brand_id: uuid.UUID | None = None
    model_id: uuid.UUID | None = None
    usage_id: uuid.UUID | None = None
    barcode: str | None = Field(default=None, max_length=100)
    part_number: str | None = Field(default=None, max_length=100)
    invoice_description: str | None = None
    memo: str | None = None
    notes: str | None = None
    dimensions: str | None = Field(default=None, max_length=255)

class StockItemAttachmentOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    stock_item_id: uuid.UUID
    filename: str
    content_type: str | None
    file_size: int | None
    created_at: datetime

class StockItemOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    company_id: uuid.UUID
    code: str
    name: str
    description: str | None
    category: str | None
    unit_of_measure: str
    product_id: uuid.UUID | None
    reorder_level: int
    # Extended fields
    category_id: uuid.UUID | None = None
    group_id: uuid.UUID | None = None
    brand_id: uuid.UUID | None = None
    model_id: uuid.UUID | None = None
    usage_id: uuid.UUID | None = None
    barcode: str | None = None
    part_number: str | None = None
    invoice_description: str | None = None
    memo: str | None = None
    notes: str | None = None
    dimensions: str | None = None
    # Joined display names
    category_name: str | None = None
    group_name: str | None = None
    brand_name: str | None = None
    model_name: str | None = None
    usage_name: str | None = None
    attachments: list[StockItemAttachmentOut] = []
    is_active: bool
    created_at: datetime
    updated_at: datetime

# ── Stock Setup Master schemas ──────────────────────────────────────

class StockCategoryCreate(BaseModel):
    code: str = Field(max_length=30)
    name: str = Field(max_length=200)

class StockCategoryOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    company_id: uuid.UUID
    code: str
    name: str
    is_active: bool
    created_at: datetime

class StockGroupCreate(BaseModel):
    code: str = Field(max_length=30)
    name: str = Field(max_length=200)

class StockGroupOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    company_id: uuid.UUID
    code: str
    name: str
    is_active: bool
    created_at: datetime

class StockBrandCreate(BaseModel):
    name: str = Field(max_length=200)

class StockBrandOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    company_id: uuid.UUID
    name: str
    is_active: bool
    created_at: datetime

class StockModelCreate(BaseModel):
    brand_id: uuid.UUID
    name: str = Field(max_length=200)

class StockModelOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    brand_id: uuid.UUID
    name: str
    is_active: bool
    created_at: datetime

class StockUsageCreate(BaseModel):
    code: str = Field(max_length=30)
    name: str = Field(max_length=200)

class StockUsageOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    company_id: uuid.UUID
    code: str
    name: str
    is_active: bool
    created_at: datetime

class StockLevelOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    stock_item_id: uuid.UUID
    warehouse_id: uuid.UUID
    quantity: int
    avg_cost: float
    # Joined fields for display
    item_code: str | None = None
    item_name: str | None = None
    warehouse_code: str | None = None
    warehouse_name: str | None = None

class StockMovementOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    stock_item_id: uuid.UUID
    warehouse_id: uuid.UUID
    movement_type: str
    quantity: int
    unit_cost: float
    total_cost: float
    reference_type: str | None
    reference_id: uuid.UUID | None
    notes: str | None
    created_at: datetime

class GRNLineCreate(BaseModel):
    stock_item_id: uuid.UUID
    quantity: int = Field(gt=0)
    unit_cost: float = Field(ge=0)
    notes: str | None = None

class GRNCreate(BaseModel):
    warehouse_id: uuid.UUID
    supplier_id: uuid.UUID | None = None
    purchase_order_id: uuid.UUID | None = None
    receive_date: datetime | None = None
    notes: str | None = None
    lines: list[GRNLineCreate] = Field(min_length=1)

class GRNLineOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    stock_item_id: uuid.UUID
    quantity: int
    unit_cost: float
    total_cost: float
    notes: str | None

class GRNOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    company_id: uuid.UUID
    grn_number: str
    warehouse_id: uuid.UUID
    supplier_id: uuid.UUID | None
    purchase_order_id: uuid.UUID | None
    receive_date: datetime
    status: str
    notes: str | None
    created_by: uuid.UUID | None
    created_at: datetime
    lines: list[GRNLineOut] = []

class GTNLineCreate(BaseModel):
    stock_item_id: uuid.UUID
    quantity: int = Field(gt=0)
    notes: str | None = None

class GTNCreate(BaseModel):
    from_warehouse_id: uuid.UUID
    to_warehouse_id: uuid.UUID
    transfer_date: datetime | None = None
    notes: str | None = None
    lines: list[GTNLineCreate] = Field(min_length=1)

class GTNLineOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    stock_item_id: uuid.UUID
    quantity: int
    notes: str | None

class GTNOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    company_id: uuid.UUID
    gtn_number: str
    from_warehouse_id: uuid.UUID
    to_warehouse_id: uuid.UUID
    transfer_date: datetime
    status: str
    notes: str | None
    created_by: uuid.UUID | None
    created_at: datetime
    lines: list[GTNLineOut] = []

class GRTNLineCreate(BaseModel):
    stock_item_id: uuid.UUID
    quantity: int = Field(gt=0)
    unit_cost: float = Field(ge=0, default=0)
    notes: str | None = None

class GRTNCreate(BaseModel):
    warehouse_id: uuid.UUID
    supplier_id: uuid.UUID | None = None
    return_date: datetime | None = None
    reason: str | None = None
    notes: str | None = None
    lines: list[GRTNLineCreate] = Field(min_length=1)

class GRTNLineOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    stock_item_id: uuid.UUID
    quantity: int
    unit_cost: float
    total_cost: float
    notes: str | None

class GRTNOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    company_id: uuid.UUID
    grtn_number: str
    warehouse_id: uuid.UUID
    supplier_id: uuid.UUID | None
    return_date: datetime
    reason: str | None
    status: str
    notes: str | None
    created_by: uuid.UUID | None
    created_at: datetime
    lines: list[GRTNLineOut] = []

class AdjustmentLineCreate(BaseModel):
    stock_item_id: uuid.UUID
    quantity_change: int  # +ve or -ve
    notes: str | None = None

class AdjustmentCreate(BaseModel):
    warehouse_id: uuid.UUID
    adjustment_date: datetime | None = None
    reason: str | None = None
    lines: list[AdjustmentLineCreate] = Field(min_length=1)

class AdjustmentLineOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    stock_item_id: uuid.UUID
    quantity_change: int
    notes: str | None

class AdjustmentOut(BaseModel):
    model_config = ConfigDict(from_attributes=True)
    id: uuid.UUID
    company_id: uuid.UUID
    adj_number: str
    warehouse_id: uuid.UUID
    adjustment_date: datetime
    reason: str | None
    status: str
    approved_by: uuid.UUID | None
    approved_at: datetime | None
    created_by: uuid.UUID | None
    created_at: datetime
    lines: list[AdjustmentLineOut] = []
