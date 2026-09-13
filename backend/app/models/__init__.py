"""Import all model modules so Base.metadata is fully populated for
Alembic autogenerate and for `create_all` in scripts/tests."""
from app.models.accounting import (  # noqa: F401
    Account,
    AccountType,
    JournalEntry,
    JournalLine,
    JournalStatus,
    VoucherType,
)
from app.models.payables import (  # noqa: F401
    BillMatchStatus,
    BillStatus,
    PurchaseOrder,
    PurchaseOrderStatus,
    SupplierInvoice,
    SupplierPayment,
    SupplierPaymentAllocation,
)
from app.models.billing import Invoice, InvoiceStatus, InvoiceType  # noqa: F401
from app.models.payments import (  # noqa: F401
    CommissionSettings,
    Payment,
    PaymentAllocation,
    PaymentMethod,
)
from app.models.tax import TaxCode  # noqa: F401
from app.services.numbering import DocumentSequence  # noqa: F401
from app.models.contracts import (  # noqa: F401
    Contract,
    ContractKind,
    ContractProduct,
    ContractStatus,
    ExcessTreatment,
    ExcessUsageRecord,
    ExpiredHoursRecord,
    LicenseDeploymentType,
)
from app.models.core import (  # noqa: F401
    AuditLogEntry,
    Company,
    LoginOtp,
    User,
    UserCompanyAccess,
    UserRole,
)
from app.models.company_individuals import (  # noqa: F401
    Branch,
    Contact,
    CompanyIndividual,
    CompanyIndividualGroup,
    CompanyIndividualRelationship,
    CompanyIndividualType,
)
from app.models.catalog import Product, ProductType  # noqa: F401
from app.models.reference_codes import ReferenceCode  # noqa: F401
from app.models.quotations import Quotation, QuotationLine, QuotationStatus  # noqa: F401
from app.models.groups import (  # noqa: F401
    ACCESS_LEVEL_ORDER,
    AccessLevel,
    Group,
    GroupModuleAuthority,
)
from app.models.job_orders import JobOrder, JobOrderPriority, JobOrderStatus  # noqa: F401
from app.models.service_records import (  # noqa: F401
    ServiceRecord,
    ServiceRecordCompletion,
    ServiceRecordOutcome,
    ServiceRecordStatus,
)
from app.models.licensing import CompanyModule, LicenseType, Module  # noqa: F401
from app.models.software_tasks import SoftwareTask  # noqa: F401
from app.models.accounting import GLType  # noqa: F401
from app.models.setup import SetupListItem, SetupListType  # noqa: F401
from app.models.treasury import BankAccount, BankReconciliation, BankTransaction, CurrencyRate  # noqa: F401
from app.models.periods import (  # noqa: F401
    AccountingPeriod,
    FiscalYearClosure,
    PeriodDocType,
    PeriodLock,
    PeriodOperation,
    PeriodStatus,
    VALID_DOC_OPERATIONS,
)
from app.models.ops_tasks import OpsTask, OpsTaskCategory, OpsTaskStatus  # noqa: F401
from app.models.announcements import AdBannerSettings, Announcement  # noqa: F401
from app.models.incidents import Incident, IncidentSource, IncidentStatus  # noqa: F401
from app.models.attachments import (  # noqa: F401
    AttachmentKind,
    ServiceRecordAttachment,
    ServiceRecordSignoff,
)
from app.models.documents import (  # noqa: F401
    DocumentAttachment,
    DocumentEntityType,
    DocumentSignature,
)
from app.models.approvals import (  # noqa: F401
    ApprovalAuthority,
    ApprovalAuthorityMember,
    ApprovalDecision,
    ApprovalDecisionValue,
    ApprovalMode,
    ApprovalRequest,
    ApprovalRule,
    ApprovalStatus,
)
