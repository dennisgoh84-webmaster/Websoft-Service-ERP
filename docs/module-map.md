# Module Map

Status: **proposed / conceptual**. This document proposes a module structure
for Websoft Service ERP Solution based on the business areas listed in
[CLAUDE.md](../CLAUDE.md) and the project-level requirements in
[business-requirements.md](business-requirements.md). It groups
functionality, describes each module's purpose, and shows how modules
depend on one another.

Nothing here is final: module boundaries, and especially the business rules
each module will enforce, are subject to the decisions tracked in
[open-business-decisions.md](open-business-decisions.md). No database
tables, APIs, or code follow from this document yet.

## How to read this document

For each module:

- **Purpose** — why the module exists.
- **Main users** — who is expected to use it day-to-day.
- **Key functions** — the main capabilities it is expected to provide.
- **Information managed** — the kinds of data it owns (conceptual, not a
  schema).
- **Depends on** — other modules it needs data or behaviour from.
- **Depended on by** — other modules that need data or behaviour from it.

"Depends on" / "Depended on by" describe expected data and functional
relationships, not a mandated code/package structure — the actual backend
layering (e.g. modular monolith vs. services) is an architecture decision
covered in [system-architecture.md](system-architecture.md).

## Module dependency overview

```
Core / Administration
        │  (auth, RBAC, audit, company/multi-entity context — used by everything below)
        ▼
Customer Management ──► CRM ──► Sales ──► Service Contracts ──► Helpdesk / Service Operations
        │                          │             │                        │
        │                          │             ▼                        ▼
        │                          │        Projects ───────────────► Service Records
        │                          │             │                        │
        │                          ▼             ▼                        ▼
        │                     Hardware Mgmt ◄─ Inventory ◄─ Purchasing    Billing
        │                          │                              │           │
        │                          └──────────────────────────────┴────► Accounts Receivable
        │                                                                      │
        │                                                          Commission Management
        │                                                                      │
        └──────────────────────────────────────────────────────────► Finance / Accounting
                                                                              │
                                                        Accounts Payable ◄────┘
                                                                              │
                                                    Reporting / Mgmt Dashboard ◄── (reads from all)
                                                                              │
                                                     Integrations ◄──► AI Assistant
```

This diagram is illustrative, not exhaustive — see each module's "Depends
on" / "Depended on by" lists for the full picture.

---

## 1. Core / Administration

**Purpose**
Provide the foundational services every other module relies on: company
identity, users, authentication, roles/permissions, system-wide settings,
and the audit log. This is the module that makes the system multi-company
ready in the future while serving a single company today.

**Main users**
System administrators, IT admin, senior management (for org-wide settings).

**Key functions**
- Staff Master: user account management (create/deactivate/reactivate,
  reset password, assign role and Group).
- **Company Setup** (confirmed 2026-09-10): the company record — name,
  country, currency, timezone, and the logo shown at the top-left of the
  app — plus creating additional companies. Multi-company is live: each
  company has its own customers, contracts, job orders, invoices, staff,
  Groups, Event Logs and module mix; staff with access to more than one
  get a company switcher, and everything they see is scoped to whichever
  is active. See system-architecture.md's Multi-company section.
- Module Control: per-company module enable/license (see Module Control /
  Licensing below).
- **Group Authority** (RBAC, confirmed 2026-09-10 — resolves open item
  8.4): define Groups and, for each Group, a per-module access level of
  None / View / Edit / Full. Every staff member belongs to exactly one
  Group per company they work in; that Group governs what they can
  see/do in each module of that company. This is
  independent of the small `role` field used only for specific
  named-responsibility business rules already confirmed elsewhere (e.g.
  SRV-004/SRV-011's excess-usage decider) — see open-business-decisions.md
  §8.4 for the full design.
- System-wide configuration (e.g. currencies, number sequences, business
  calendar/holidays).
- Central audit log service used by other modules.
- **Event Logs** (confirmed 2026-09-10, module key `event_logs`): the
  security-gated master over that audit log -- who created, edited (to
  what old -> new value), or deleted a record, and who generated which
  report, each with the acting staff member's name, IP address, and a
  persistent per-browser device id (a real hardware/PC serial number
  isn't obtainable from a browser). Deliberately its own Group Authority
  module, not folded into general admin access, so viewing the
  system-wide audit trail is a separately configurable permission
  (default seed: Owner / Admin only). See system-architecture.md's Audit
  logging section for the full design.
- Master reference data shared across modules (e.g. countries, tax
  categories) where not owned by a more specific module.

**Information managed**
Companies/entities, users, employees (as system accounts), roles, Groups
and their per-module Group Authority matrix, audit log entries (Event
Logs), system settings, shared reference/lookup data.

**Depends on**
None — this is the foundational module.

**Depended on by**
Every other module (CRM, Sales, Customer Management, Service Contracts,
Helpdesk/Service Operations, Projects, Service Records, Billing, Accounts
Receivable, Accounts Payable, Purchasing, Inventory, Hardware Management,
Commission Management, Finance/Accounting, Reporting, Integrations, AI
Assistant) — all rely on it for authentication, RBAC, audit logging, and
company context.

---

## 2. CRM

**Purpose**
Manage the pre-sales relationship: leads, opportunities, and the sales
pipeline, before a customer has a confirmed order or contract.

**Main users**
Sales team, sales/business development management.

**Key functions**
- Lead capture and qualification.
- Opportunity/pipeline management (stages, forecasted value, close date).
- Activity tracking (calls, meetings, follow-ups) linked to leads/
  opportunities.
- Conversion of a qualified opportunity into a quotation (handed to Sales).

**Information managed**
Leads, opportunities, pipeline stages, sales activities/interactions,
opportunity-to-contact/customer links.

**Depends on**
Core / Administration (users, RBAC, audit); Customer Management (existing
customer/contact records, for opportunities tied to existing customers).

**Depended on by**
Sales (opportunities feed quotations); Reporting (pipeline/forecast
reporting); AI Assistant (e.g. future lead scoring or pipeline insights).

---

## 3. Sales

**Purpose**
Turn qualified opportunities into formal commercial commitments:
quotations and sales orders, including pricing.

**Main users**
Sales team, sales management, order desk/sales administration.

**Key functions**
- Quotation creation (from an opportunity or directly).
- Pricing and discounting.
- **Quotation approval by Cherish (Sales Manager)** before it is sent to
  the customer — every quotation, no threshold exemption (CONFIRMED,
  BILL-006).
- Sales order creation and confirmation from an accepted quotation.
- Tracking order status through to fulfilment (contract setup, hardware
  delivery, or project kickoff, depending on what was sold).

**Information managed**
Quotations, quotation lines, sales orders, sales order lines, pricing
terms, sales ownership (which salesperson/account owns the deal).

**Depends on**
Core / Administration; CRM (originating opportunity); Customer Management
(customer/contact/billing details); Inventory (product/hardware
availability, where hardware is quoted); Finance / Accounting (tax
treatment, currency).

**Depended on by**
Service Contracts (a contract may originate from a sales order); Projects
(a project may originate from a sales order); Hardware Management (a sales
order for hardware triggers delivery); Billing (sales orders/quotations
feed invoicing); Commission Management (commission is calculated from
sales); Reporting.

---

## 4. Customer Management

**Purpose**
Own the authoritative record of who the customer is: the customer/company
record, its contacts, sites, and ownership — independent of any one deal,
contract, or job order.

**Main users**
Sales, account managers, customer service, finance (for billing details).

**Key functions**
- Customer/company master record management.
- Contact management (people at a customer, roles, communication
  preferences).
- Site/location management (for on-site service or hardware delivery).
- Customer ownership assignment (account owner).
- Customer status (active, prospect, inactive/archived).

**Information managed**
Customers, contacts, customer sites/locations, customer ownership,
customer classification/segmentation.

**Depends on**
Core / Administration.

**Depended on by**
CRM, Sales, Service Contracts, Helpdesk / Service Operations, Projects,
Billing, Accounts Receivable, Hardware Management, Reporting — essentially
every customer-facing module.

---

## 5. Service Contracts

**Purpose**
Manage recurring service commitments to customers: contract terms,
included hours/scope, and the renewal cycle.

**Main users**
Sales (at contract setup/renewal), service operations, contract
administration, finance (for recurring billing terms).

**Key functions**
- Contract creation from a sales order/quotation, starting in **Draft**
  status.
- Definition of contract lines (services covered, included hours/quantity,
  scope). A standard contract runs **12 months** and requires a
  **minimum of 10 contracted support hours** (CONFIRMED, SRV-001/SRV-002
  — see
  [business-requirements.md](business-requirements.md#service-operations-business-rules-confirmed));
  creation below that minimum is blocked with **no override mechanism**
  (CONFIRMED, SRV-012) — 10 hours is a hard minimum until Dennis decides
  otherwise.
- Tracking contract consumption (hours/quantity used vs. remaining), with
  each logged time entry **rounded up to the nearest 15 minutes** before
  deduction (CONFIRMED, SRV-007). The balance can **never go negative**;
  once fully consumed, further usage is Excess Usage requiring Nico's (or
  Cherish's, as backup — CONFIRMED, SRV-011) review rather than continued
  deduction (CONFIRMED, SRV-003/SRV-004).
- Recording Excess Usage separately and visibly, with the reviewer's
  treatment decision and reason kept as an auditable record (CONFIRMED,
  SRV-004). Treatment categories are: billable excess (billed at the
  contract's blended rate, no customer pre-approval needed — CONFIRMED,
  SRV-008), approved non-billable, Warranty/Goodwill, or Internal
  Write-off (CONFIRMED categories, SRV-013).
- Contract lifecycle/status management: **Draft → Active → Exceeded (if
  applicable) → Expired / Renewed** (CONFIRMED, SRV-001).
- Expiring all unused contract hours completely at the end of the
  12-month period — no roll-over, no credit conversion, no transfer —
  and retaining an Expired Hours record for reporting/audit (CONFIRMED,
  SRV-005). A renewal creates a **new Contract record** (referencing the
  prior one for history) with its own new hour allocation, backdated to
  immediately follow the prior contract's expiry so there is no coverage
  gap, provided renewal happens within **2 weeks** of expiry (CONFIRMED
  maximum window, SRV-010/SRV-016). Beyond 2 weeks, the renewal is a
  fresh, non-contiguous contract (handling not yet decided).
- Triggering renewal opportunities ahead of expiry, including a
  pre-expiry check — starting **30 days before expiry** (CONFIRMED,
  SRV-014) — for open job orders, missing service records, and unapproved/unbilled
  excess usage (CONFIRMED requirement, SRV-006).

**Information managed**
Contracts (with lifecycle status, start/expiry dates), contract lines,
contract terms, Contract Hour Consumption Records, Excess Usage
Records (with Nico's decision and reason), Expired Hours Records, renewal
history.

**Depends on**
Core / Administration; Customer Management; Sales (originating quotation/
order).

**Depended on by**
Helpdesk / Service Operations (job orders consume contract hours); Billing
(recurring contract billing); CRM/Sales (renewal opportunities); Reporting.

---

## 6. Helpdesk / Service Operations (Job Orders)

**Purpose**
Manage customer-reported issues and service requests from intake through
resolution, and connect that work to contracts, staffing, and billing.

**Main users**
Service/support staff, service operations managers; customers may
eventually raise job orders via a portal (not yet decided).

**Key functions**
- Job Order intake, categorization, and prioritization.
- Tracking job order priority and timestamps. There are **no SLA
  targets and none are planned** (removed 2026-09-15, Dennis: "not
  necessary to have").
- Staff assignment and escalation.
- Linking job orders to the relevant contract (for hour validation) and/or
  hardware asset.
- Validating logged service time against the contract's remaining
  balance before deduction, and routing usage beyond entitlement to
  Nico's (or Cherish's, as backup) Excess Review instead of
  auto-deducting or auto-billing (CONFIRMED, SRV-003/SRV-004/SRV-011 —
  see Service Contracts above).
- Job Order resolution and closure tracking, feeding the pre-expiry
  "Unaccounted Service Activity" check (CONFIRMED, SRV-006).

**Information managed**
Job Orders, job order status/history, assignment records,
job order-to-contract links, and job order-to-asset links.

**Depends on**
Core / Administration; Customer Management; Service Contracts (to know
entitlement/hours); Hardware Management (when a job order relates to
a specific asset).

**Depended on by**
Service Records (time logged against job orders); Billing (billable job order work,
work beyond contract entitlement); Reporting.

---

## 7. Projects

**Purpose**
Manage discrete, scoped bodies of work delivered to a customer (e.g.
implementation projects), as distinct from ongoing contract-based support.

**Main users**
Project managers, consultants/engineers delivering the work, service
operations management.

**Key functions**
- Project setup (scope, timeline, budget) — often from a sales order.
- Task/milestone breakdown and assignment.
- Progress tracking.
- Linking project cost (via service records) to project billing — billed on a
  **fixed price / milestone** basis, not time-and-materials (CONFIRMED,
  BILL-004).

**Information managed**
Projects, project tasks/milestones, project budgets, project status,
project-to-customer and project-to-sales-order links.

**Depends on**
Core / Administration; Customer Management; Sales (originating sales
order, where applicable).

**Depended on by**
Service Records (time logged against project tasks); Billing (milestone-based
project billing, CONFIRMED BILL-004); Reporting.

---

## 8. Service Records

**Purpose**
Capture how staff time is spent against job orders, projects, or
contracts, as the basis for cost tracking, contract hour deduction, and
billing.

**Main users**
Any billable staff (engineers, consultants, support staff), with approval
by managers.

**Key functions**
- Time entry (by employee, date, task/job order/project), required within
  **3 business days** of the work being performed (CONFIRMED, SRV-015);
  later entries are flagged as missing.
- Approval workflow for submitted time.
- Classification of time as billable, non-billable, or contract-covered
  — for contract-linked time, this classification is driven by the
  Service Contracts hour validation (CONFIRMED, SRV-003/SRV-004), with
  entries rounded up to the nearest 15 minutes before deduction
  (CONFIRMED, SRV-007): covered if hours remain, otherwise routed as
  Excess Usage.
- Feeding approved time into contract consumption, project cost, and
  billing.
- Surfacing missing/overdue service records (per the SRV-015 3-business-day
  window), which feed the SRV-006/SRV-014 pre-expiry "Unaccounted Service
  Activity" check.

**Information managed**
Service Records, approval status/history, time categorization
(billable/non-billable/contract).

**Depends on**
Core / Administration (employees); Helpdesk / Service Operations (job orders
time is logged against); Projects (tasks time is logged against); Service
Contracts (to know whether time is covered by contract hours).

**Depended on by**
Billing (billable time becomes invoice lines); Service Contracts (approved
time deducts from contract hours, per rules still to be decided);
Commission Management (if commission considers delivered service, to be
decided); Finance / Accounting (labour cost); Reporting.

---

## 9. Billing

**Purpose**
Generate invoices from the various sources of billable activity across
the business: sales orders, contracts, helpdesk work, projects, and
hardware.

**Main users**
Finance/billing team.

**Key functions**
- Consolidating billable items from Sales, Service Contracts, Helpdesk,
  Projects, and Hardware Management into invoices.
- Recurring contract billing: **annual upfront** — full 12-month value
  billed at contract start/renewal (CONFIRMED, BILL-001).
- Milestone-based billing for Projects (CONFIRMED, BILL-004); one-off
  billing for sales orders/hardware.
- Invoicing excess service usage approved as billable by Nico (or
  Cherish, as backup), at the **contract's own blended rate** with **no
  customer pre-approval required** before invoicing (CONFIRMED, SRV-004/
  SRV-008/SRV-011), and ensuring no completed service activity is left
  permanently unaccounted for: every item must resolve to
  contract-covered, billed, approved non-billable, Warranty/Goodwill,
  Internal Write-off, or another explicitly approved treatment
  (CONFIRMED requirement, SRV-006/SRV-013).
- **No approval required before issuing an invoice** — system-generated
  invoices are issued directly (CONFIRMED, BILL-002).
- **Credit note issuance**: approved by finance or Cherish for routine
  cases; above a value threshold, Dennis approves (CONFIRMED, BILL-003;
  exact threshold not yet specified).
- Revenue recognized **on invoice** for contracts, projects, and hardware
  alike (CONFIRMED, BILL-005).
- Handing finalized invoices to Accounts Receivable and, in future, to
  Integrations for InvoiceNow/Peppol submission.

**Information managed**
Invoices, invoice lines, credit notes, billing runs/cycles, billing rules
per contract/customer (where applicable).

**Depends on**
Core / Administration; Customer Management; Sales; Service Contracts;
Helpdesk / Service Operations; Projects; Service Records; Hardware Management;
Finance / Accounting (tax/GST treatment).

**Depended on by**
Accounts Receivable (invoices become receivables); Finance / Accounting
(revenue recognition/GL posting); Integrations (InvoiceNow/Peppol
submission); Reporting.

---

## 10. Accounts Receivable

**Purpose**
Track what customers owe, record payments received, and manage the
collections/reconciliation cycle.

**Main users**
Finance / accounts team.

**Key functions**
- Tracking invoice status (open, partially paid, paid, overdue).
- Recording customer payments and **allocating them manually**, based on
  remittance information — no automatic allocation rule (CONFIRMED,
  AR-001).
- Aging analysis and outstanding balance reporting; a **disputed
  invoice continues through normal collections/aging** with no automatic
  hold (CONFIRMED, AR-003).
- Write-offs of small unreconciled differences or bad debt: finance can
  write off small amounts directly; above a threshold, Dennis approves
  (CONFIRMED, AR-002; exact threshold not yet specified).
- Reconciliation of payments to bank records (in coordination with
  Finance / Accounting and, in future, Integrations).

**Information managed**
Customer payments, payment allocations, AR aging data, outstanding
balances.

**Depends on**
Billing (source invoices); Customer Management; Finance / Accounting;
Core / Administration.

**Depended on by**
Finance / Accounting (cash and revenue position); Commission Management
(if commission payout is tied to customer payment, to be decided);
Reporting.

---

## 11. Accounts Payable

**Purpose**
Track what the company owes to suppliers, and manage supplier invoice
approval and payment.

**Main users**
Finance / accounts team, procurement (for invoice matching).

**Key functions**
- Recording supplier invoices, matched against the **Purchase Order only
  — 2-way matching**, not a separate goods-receipt match (CONFIRMED,
  PUR-002).
- Supplier invoice payment is **auto-approved once it matches the PO**
  (CONFIRMED, PUR-003); a mismatch is handled as an exception (handling
  not yet decided).
- Scheduling and recording payments to suppliers.
- Outstanding payable tracking and aging.

**Information managed**
Supplier invoices, payment records to suppliers, AP aging data.

**Depends on**
Purchasing (purchase orders to match against); Core / Administration;
Finance / Accounting.

**Depended on by**
Finance / Accounting (cash and expense position); Reporting.

---

## 12. Purchasing

**Purpose**
Manage the procurement of goods (including hardware for resale/
installation) and services from suppliers.

**Main users**
Procurement/purchasing staff, with approval by finance/management.

**Key functions**
- Supplier master data (may live here or in Core / Administration —
  boundary to be confirmed during detailed design).
- Purchase requisition and purchase order creation.
- Purchase order approval: **value-based** — below a threshold,
  procurement/finance approve directly; above it, Dennis approves
  (CONFIRMED, PUR-001; exact threshold not yet specified).
- Goods receipt recording.
- Linking received goods to Inventory and, for hardware, to Hardware
  Management.

**Information managed**
Suppliers (procurement-relevant data), purchase orders, purchase order
lines, goods receipts.

**Depends on**
Core / Administration; Inventory (stock levels/reorder needs); Hardware
Management (hardware-specific purchase needs); Finance / Accounting
(budget/approval).

**Depended on by**
Accounts Payable (supplier invoices matched to POs/receipts); Inventory
(goods receipt increases stock); Hardware Management (received hardware
enters asset tracking); Reporting.

---

## 13. Inventory

**Purpose**
Track stock of physical items (including hardware) across locations, and
record stock movements.

**Main users**
Warehouse/stock staff, procurement, hardware team.

**Key functions**
- Stock item master data (non-serialized items).
- Stock level tracking by location, valued at **weighted average cost**
  (CONFIRMED, INV-002).
- Stock movement recording (receipt, issue, transfer, adjustment).
- Stock adjustments **require manager approval** before taking effect
  (CONFIRMED, INV-001) — staff cannot adjust stock unilaterally.

**Information managed**
Inventory items, stock levels, stock movements, warehouse/location
records.

**Depends on**
Core / Administration; Purchasing (goods receipt increases stock).

**Depended on by**
Hardware Management (serialized hardware assets originate from inventory
receipt); Sales (product/hardware availability at quoting time); Billing
(stock issued for a sale/installation feeds billing); Purchasing (stock
levels inform reordering); Reporting.

---

## 14. Hardware Management

**Purpose**
Track individual hardware assets (serialized equipment) from receipt
through delivery, customer installation, and ongoing service coverage —
distinct from generic inventory quantity tracking.

**Main users**
Field engineers, warehouse/hardware team, service operations.

**Key functions**
- Serial/asset number tracking for hardware items.
- Linking an asset to the sales order it was sold under.
- Delivery and installation recording (including customer site).
- **Customer sign-off/acceptance is required** before an installation is
  considered complete and billable (CONFIRMED, HW-001) — the installing
  engineer's own confirmation is not sufficient on its own.
- Linking installed assets to job orders and, where relevant, to
  service contract coverage.
- Warranty tracking (terms not yet decided) and RMA/replacement handling
  for hardware failures (process not yet decided).

**Information managed**
Hardware assets (serial-tracked), asset status (in stock, delivered,
installed, retired), asset-to-customer/site links, warranty information.

**Depends on**
Inventory (assets originate from stock receipt); Sales (asset sold under
a sales order); Customer Management (installation site/customer);
Purchasing (asset originates from a purchase).

**Depended on by**
Helpdesk / Service Operations (job orders linked to a specific asset);
Service Contracts (contracts may cover specific assets); Billing
(hardware sale/installation billing); Reporting.

---

## 15. Commission Management

Status: **DEFERRED** (2026-09-10, at Dennis's request) — not being
worked on for now; revisit once Service Operations (and related areas)
are finalized. Listed in the Module Control catalog as not yet built.

**Purpose**
Calculate, approve, and track commission owed to sales staff based on
sales performance.

**Main users**
Sales management, finance, (HR/payroll as a downstream consumer, not yet
scoped).

**Key functions**
- Commission calculation from sales data (calculation rule to be
  decided).
- Commission approval workflow (approver and rule to be decided).
- Tracking when commission becomes payable (trigger to be decided — e.g.
  on invoicing vs. on customer payment).
- Commission payment tracking (handed to Finance / Accounting / Accounts
  Payable-style payout, mechanism to be decided).

**Information managed**
Commission calculations, commission approval status/history, commission
payment records.

**Depends on**
Sales (source sales data); Accounts Receivable (if commission is tied to
customer payment, to be decided); Core / Administration (sales
employee/role); Finance / Accounting.

**Depended on by**
Finance / Accounting (commission as a payable/expense); Reporting.

---

## 16. Finance / Accounting

**Purpose**
Maintain the company's general ledger and financial records: the
authoritative financial view that AR, AP, Billing, and Commission
Management post into, and the basis for statutory/financial reporting
(including GST).

**Main users**
Finance / accounting team, senior management.

**Key functions**
- Chart of accounts and general ledger.
- Posting of transactions originating in Billing, Accounts Receivable,
  Accounts Payable, and Commission Management.
- GST treatment and reporting.
- Financial statement production (structure/frequency to be decided).
- Multi-company-ready ledger structure (future).

**Information managed**
Chart of accounts, journal entries/general ledger, tax records, financial
statements/reports.

**Depends on**
Accounts Receivable, Accounts Payable, Billing, Commission Management
(as sources of financial transactions); Core / Administration.

**Depended on by**
Reporting (financial reporting); Integrations (banking, tax filing,
future auditor access).

---

## 17. Reporting / Management Dashboard

**Purpose**
Provide cross-module reporting and dashboards for operational and
management decision-making, without owning transactional data itself.

**Main users**
Management, department heads, finance.

**Key functions**
- Operational dashboards (e.g. open job orders, project status, sales
  pipeline).
- Financial reporting (drawing from Finance / Accounting, AR, AP).
- Management KPIs across business areas.
- Ad hoc/cross-module reporting (scope to be refined during detailed
  design).
- **Service Operations dashboard** (CONFIRMED requirement — see
  [business-requirements.md](business-requirements.md#service-operations-dashboard-requirements)),
  covering: active contracts, contracts expiring soon (flagged starting
  30 days before expiry — CONFIRMED, SRV-014), contracted hours, used
  hours, remaining usable hours, expired hours, excess hours, excess
  hours awaiting review by Nico or Cherish (CONFIRMED backup, SRV-011),
  missing service records (per the SRV-015 3-business-day submission window),
  open job orders, service activities requiring accounting/billing action,
  and renewals required.

**Information managed**
No data of its own by default — it reads and aggregates data owned by
other modules. Whether it needs its own materialized/aggregated data
stores is an architecture decision, not a business one.

**Depends on**
All other business modules, as a read-only consumer: Core / Administration,
CRM, Sales, Customer Management, Service Contracts, Helpdesk / Service
Operations, Projects, Service Records, Billing, Accounts Receivable, Accounts
Payable, Purchasing, Inventory, Hardware Management, Commission
Management, Finance / Accounting.

**Depended on by**
None functionally required it — it is a top-level consumer, though the
AI Assistant may use it as a data source.

---

## 18. Integrations

Status: Odoo migration planning within this module is **DEFERRED**
(2026-09-10, at Dennis's request); revisit once Service Operations (and
related areas) are finalized. Listed in the Module Control catalog as
not yet built.

**Purpose**
Manage the system's connections to external systems: Odoo (during the
phased migration/parallel-run), InvoiceNow/Peppol for e-invoicing, and
other future external services (e.g. banking).

**Main users**
IT/system administrators, developers; not typically an end-user-facing
module.

**Key functions**
- Odoo data sync/import during the parallel-run period (per module, as
  each is phased in).
- InvoiceNow / Peppol submission of invoices (Access Point integration —
  provider not yet selected).
- Other external integrations as identified (e.g. banking, tax filing).
- Integration monitoring/error handling.

**Information managed**
Integration configuration, sync/import logs, mapping between Websoft
Service ERP Solution records and external-system identifiers (e.g. Odoo
IDs).

**Depends on**
Core / Administration; the specific module each integration serves (e.g.
Billing for InvoiceNow, Finance / Accounting for banking); Customer
Management and other master-data modules being synced from Odoo.

**Depended on by**
Billing (InvoiceNow submission); Finance / Accounting (banking/
reconciliation); any module receiving Odoo data during its parallel-run
phase.

---

## 19. AI Assistant

**Purpose**
Provide an AI-assisted layer across the ERP — e.g. natural-language
queries over business data, suggestions, or automation assistance —
subject to the same access control and audit requirements as the rest of
the system.

**Main users**
All staff (as an assistant within their permitted scope), management (for
insights).

**Key functions**
Built 2026-09-15 (slice 1, see [planned-work.md #12](planned-work.md)):
**incident triage + resolution suggestions** — for a logged Incident,
suggest the customer, contract, priority and route, the similar
resolved incidents and what fixed them, and a draft reply; staff apply
it through the existing Incident actions; and (slice 2) a **chat
panel** on the Incidents, Company / Individual, Contract, Job Order
and Service Records screens answering in the language asked, over
read-only tools that run as the user. Rule: the assistant calls
the tested services and narrates, never does arithmetic on money or
hours, and proposes rather than commits. Candidates not yet built:
Service Record drafting, AR collections, natural-language reporting,
portal self-service, renewal prioritisation, supplier bill capture.

**Information managed**
`ai_settings` (one global row: provider API key — write-only,
encrypted — model, personal-data mask toggle, the assistant's name and
avatar) and `ai_interactions`
(the audit of every call: who, which record, model, tokens, status,
the answer; never the prompt). Everything else is read from other
modules within the requesting user's permissions.

**Depends on**
Core / Administration (authentication, RBAC, audit); Reporting (as a
likely data source); potentially any module it is asked to surface data
from.

**Depended on by**
None currently — it is a consumer-facing layer. It may feed suggestions
back into other modules in the future, which would be a further decision.
