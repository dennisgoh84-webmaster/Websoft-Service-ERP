# Major Business Workflows

Status: **proposed / conceptual**. These are proposed end-to-end workflows
across the modules described in [module-map.md](module-map.md), based on
current documentation. They describe the expected *shape* of each process
so that architecture and detailed requirements can be planned — they are
**not** confirmed business rules.

Wherever a step depends on a business rule that has not been provided,
this document flags it and points to
[open-business-decisions.md](open-business-decisions.md) rather than
assuming an answer.

---

## A. Lead → Opportunity → Quotation → Sales Order

**Trigger**
A new lead is captured (e.g. inbound enquiry, referral, outbound
prospecting).

**Steps**
1. Lead is captured and recorded in CRM.
2. Lead is qualified; if valid, converted into an Opportunity with an
   estimated value and stage.
3. Opportunity progresses through pipeline stages as sales activity
   continues (calls, meetings — tracked in CRM).
4. Sales prepares a Quotation (in Sales) from the opportunity, including
   products/services/hardware and pricing.
5. **Cherish (Sales Manager) approves the quotation** (CONFIRMED,
   BILL-006), then it is sent to the customer for review; revisions may
   occur (each revision re-enters approval).
6. Customer accepts the quotation.
7. Quotation is converted into a confirmed Sales Order.
8. Sales Order is handed off to the relevant fulfilment path: Service
   Contracts (if a contract was sold), Projects (if a project was sold),
   and/or Hardware Management (if hardware was sold).

**Responsible user/department**
Sales team (CRM and Sales modules); sales management for oversight.

**Data created**
Lead record, Opportunity record, Quotation and quotation lines, Sales
Order and sales order lines, activity/interaction history.

**Approval points**
- **Cherish (Sales Manager) approves every quotation** before it is sent
  to the customer (CONFIRMED, BILL-006) — no threshold exemption.
- Sales Order confirmation — likely requires explicit customer acceptance,
  but the confirmation mechanism (signed quote, PO from customer, verbal)
  is not yet decided.

**Financial impact**
No financial posting at Lead/Opportunity stage. A Quotation is not yet a
financial commitment. A confirmed Sales Order creates the basis for future
revenue (via Billing) but is not itself a financial transaction until
invoiced.

**Possible exceptions**
- Opportunity lost/abandoned before quotation.
- Quotation rejected or expires without acceptance.
- Partial acceptance (customer accepts some lines, not all).
- Sales order later amended or cancelled after confirmation.

**Automation opportunities**
- Auto-reminders for stale opportunities/quotations.
- Auto-numbering and templated quotation documents.
- Automatic handoff notification to Service Contracts/Projects/Hardware
  Management when a sales order is confirmed.

---

## B. New Customer → Contract → Service Setup

**Trigger**
A confirmed Sales Order includes a service contract (from Workflow A), or
an existing customer purchases a new contract directly.

**Steps**
1. If the customer is new, a Customer Management record (and contacts/
   sites) is created or confirmed.
2. A Service Contract is created from the sales order, with contract
   lines defining scope and included hours/quantity. The
   contract starts in **Draft** status (CONFIRMED lifecycle, SRV-001:
   Draft → Active → Exceeded (if applicable) → Expired / Renewed). A
   standard contract runs for **12 months** (SRV-001) and must specify at
   least **10 contracted support hours** (SRV-002) — the system must
   block creation of a normal contract below that minimum, with **no
   override mechanism** (CONFIRMED, SRV-012): 10 hours is a hard minimum
   until Dennis decides otherwise.
3. Contract is activated (status moves from Draft to **Active**, with a
   stored start date and expiry date per SRV-001).
4. Service setup activities occur as needed (e.g. onboarding tasks,
   initial hardware installation via Workflow G, access provisioning) —
   the extent of "service setup" as a formal step is not yet defined.
5. Contract becomes the reference point for Helpdesk entitlement checks
   and recurring Billing.

**Responsible user/department**
Sales (contract creation from order), contract administration/service
operations (setup), Customer Management owner (customer record).

**Data created**
Customer record (if new), Contract and contract lines, contract activation
record, service setup/onboarding records (if tracked).

**Approval points**
- Contract terms approval before activation (who signs off on
  non-standard terms is not yet decided).
- **No customer credit check is required** before contract activation
  (CONFIRMED, SRV-017) — contracts activate on the commercial agreement
  alone.

**Financial impact**
Contract value is invoiced **annually upfront** at contract start
(CONFIRMED, BILL-001) — the full 12-month value is billed immediately
rather than recognized progressively; see Workflow H for the billing
mechanics. Contract value may also be used for forecasting/reporting.

**Possible exceptions**
- Contract creation without a prior sales order (e.g. manually entered).
- Customer onboarding delays contract activation.
- Contract terms renegotiated before or shortly after activation.

**Automation opportunities**
- Auto-creation of a draft contract from an accepted quotation/sales
  order.
- Checklist/task automation for standard service setup steps.
- Notification to Billing when a contract becomes active, to schedule
  recurring billing.

---

## C. Customer → Job Order → Assignment → Service Work → Service Record → Contract Hour Validation → Contract Deduction OR Excess Review → Billing Decision → Invoice (if billable) → Complete / Auditable Record

Status: steps 5–8 (service record deadline, hour validation, excess handling,
and the "no permanently unbilled" requirement) are **CONFIRMED** per
SRV-001 through SRV-015 in
[business-requirements.md](business-requirements.md#service-operations-business-rules-confirmed).
The remaining steps follow the same proposed/conceptual status as the
rest of this document.

**Trigger**
A customer reports an issue or service request.

**Steps**
1. **Job Order** is logged in Helpdesk / Service Operations, linked
   to the customer (and asset, if applicable via Hardware Management).
2. Job Order is categorized/prioritized and checked against the
   customer's Service Contract (no SLA targets exist — removed
   2026-09-15).
3. **Assignment** — job order is assigned to a staff member (or escalated).
4. **Service Work** is performed by the assigned staff member.
5. **Service Record** — staff logs time against the job order via Service Records,
   within **3 business days** of doing the work (CONFIRMED, SRV-015);
   later than that, it is flagged as a missing service record. Service Record
   approval is required before the entry affects contract hours or
   billing: **only Nico or Cherish approve, within a week of
   submission** (CONFIRMED, SRV-019); what the approved time *is* —
   contract-covered, billable or non-billable — follows the Job
   Order's billing classification, never the submitter's choice
   (CONFIRMED, SRV-020).
6. **Contract Hour Validation** (CONFIRMED, SRV-003/SRV-004/SRV-007) —
   the approved logged time is first **rounded up to the nearest 15
   minutes** (SRV-007), then checked against the contract's remaining
   usable balance:
   - If hours remain on the contract, proceed to **Contract Deduction**:
     the Service Contract's remaining balance is reduced by the rounded
     time. No further billing step is required for this time (Billing
     Decision = "contract-covered, no invoice").
   - If the contract's hours are already fully consumed, there is **no
     grace period** (SRV-003): the logged time is immediately **Excess
     Review** instead of being deducted. The contract balance is never
     allowed to go negative (SRV-004).
7. **Excess Review (Nico, or Cherish as backup)** (CONFIRMED, SRV-004/
   SRV-011) — for any excess usage, Nico (responsible for Service &
   Support), or Cherish (Sales Manager) when Nico is unavailable, reviews
   it and decides its treatment: billable excess support, approved
   non-billable support, Warranty/Goodwill, Internal Write-off, or
   another authorized treatment (CONFIRMED categories, SRV-013). The
   decision and reason are recorded for audit. The system does not
   auto-decide this.
8. **Billing Decision** (CONFIRMED shape, SRV-006/SRV-008) — every
   completed service activity must resolve to exactly one of: contract
   hours consumed (no invoice), billable excess (proceed to invoicing at
   the contract's blended rate, no customer pre-approval required —
   SRV-008), approved non-billable excess / Warranty-Goodwill / Internal
   Write-off (reason recorded, no invoice), or another explicitly
   approved treatment. Nothing is left as indefinitely "unbilled."
9. **Invoice (if billable)** — billable excess time flows to Billing as
   a billable line and is invoiced.
10. Job Order is resolved and closed; the full chain from job order to
    (non-)invoice is a **Complete / Auditable Record**.

**Responsible user/department**
Service/support staff (job order handling, time logging); service operations
management (assignment/escalation); **Nico** (Service &
Support — excess usage review and treatment decision, per SRV-004), with
**Cherish** (Sales Manager) as the confirmed backup reviewer when Nico is
unavailable (SRV-011); finance/billing team (billing/invoicing step).

**Data created**
Job Order, assignment/escalation history, service records,
Contract Hour Consumption Records, Excess Usage Records (with Nico's
decision and reason), (conditionally) billable line items and invoices.

**Approval points**
- Service Record approval before it affects contract hours or billing (manager
  approval expected, but the approver and threshold are not yet decided —
  [open-business-decisions.md](open-business-decisions.md), item 9.1).
- Excess usage treatment decision by Nico, or Cherish as backup (CONFIRMED,
  SRV-004/SRV-011) — a defined, mandatory approval point.

**Financial impact**
No financial posting for contract-covered time. Financial impact occurs
when excess time is approved by Nico as billable and invoiced via
Billing. Approved non-billable excess has no direct financial posting but
must be recorded with its reason for audit and reporting.

**Possible exceptions**
- Contract hours are exhausted partway through a job order — CONFIRMED handling: no grace
  period, excess usage routed to Nico's (or Cherish's) review rather than
  auto-billed or auto-absorbed (SRV-003/SRV-004).
- Job Order reassigned multiple times.
- Work performed is later disputed by the customer.
- Service Record not submitted within 3 business days (CONFIRMED, SRV-015) —
  flagged as a missing service record, feeding the SRV-014 pre-expiry review
  (see Workflow I) and the Service Operations dashboard.

**Automation opportunities**
- Auto-assignment based on staff availability/skill.
- Automatic contract-hour rounding (SRV-007), validation, and deduction
  from approved service records (CONFIRMED as required behaviour; automation
  of the underlying check, not just the rule, is a future implementation
  detail).
- Automatic flagging of job orders nearing contract hour exhaustion, and
  automatic creation of an Excess Usage Record (routed to Nico, or
  Cherish as backup) once exhausted, instead of a person having to
  notice manually.
- Automatic flagging of a service record not submitted within 3 business days
  (SRV-015) as missing.
- Periodic scan for "Unaccounted Service Activity" per SRV-006 (see
  Workflow I and the Service Operations dashboard requirements in
  [business-requirements.md](business-requirements.md)).

---

## D. Project → Tasks → Service Record → Cost → Revenue → Billing

**Trigger**
A Sales Order for project-based work is confirmed, or a project is set up
directly for an existing engagement.

**Steps**
1. Project is created in Projects, with scope, budget, and timeline.
2. Project is broken into tasks/milestones and assigned to staff.
3. Staff log time against project tasks via Service Records.
4. Logged (approved) time contributes to project cost tracking (labour
   cost) — cost rate source (e.g. standard cost per role) is not yet
   decided.
5. Project revenue is recognized **on invoice** (CONFIRMED, BILL-005),
   using the project's confirmed **fixed price / milestone** billing
   method (CONFIRMED, BILL-004) — not time-and-materials.
6. Billing generates invoices from milestone completion events.

**Responsible user/department**
Project managers (setup, task assignment, progress tracking); project
staff (time logging); finance/billing team (billing step).

**Data created**
Project record, tasks/milestones, service records, project cost
accumulation, billing events/lines.

**Approval points**
- Service Record approval (as in Workflow C).
- Milestone completion sign-off (for milestone billing) — approver not
  yet decided.
- Project budget overrun approval — **whether/when a project needs
  re-approval if it exceeds budget is not yet decided.**

**Financial impact**
Project cost accrues as time is logged; revenue is recognized **on
invoice** at each milestone (CONFIRMED, BILL-004/BILL-005). Both cost and
revenue feed Finance / Accounting and project profitability reporting.

**Possible exceptions**
- Project runs over budget or over timeline.
- Project paused or cancelled mid-way.
- Scope change requiring a contract/quotation amendment.

**Automation opportunities**
- Budget-vs-actual alerts as service records are logged.
- Automatic draft invoice generation on milestone completion (CONFIRMED
  billing method, BILL-004).
- Project status dashboards (Reporting).

---

## E. Sales → Commission Calculation → Commission Approval → Payment

**Trigger**
A sale is confirmed (Sales Order) and/or invoiced/paid, depending on when
commission is deemed earned (not yet decided).

**Steps**
1. Sales data (order and/or invoice and/or payment, depending on the
   trigger point decided) becomes eligible for commission calculation.
2. Commission Management calculates the commission amount owed to the
   relevant salesperson(s) — **the calculation formula (e.g. flat
   percentage, tiered, product-specific rates, split commissions) is not
   yet decided.**
3. Calculated commission is submitted for approval.
4. Approver reviews and approves (or rejects/adjusts) the commission.
5. Approved commission becomes payable and is scheduled for payment —
   **the payment mechanism (e.g. via payroll, via Accounts Payable-style
   payout) is not yet decided.**
6. Payment is recorded.

**Responsible user/department**
Sales management (visibility), finance (calculation/administration),
designated approver (approval), HR/payroll (payment execution, if
applicable — not yet scoped as a module).

**Data created**
Commission calculation records, approval history, commission payment
records.

**Approval points**
- Commission approval before payment — **approver role, and whether
  multi-level approval is required for large amounts, is not yet
  decided.**

**Financial impact**
Commission is an expense/payable for the company once approved. It
affects sales cost-of-sale/profitability reporting and, once paid,
reduces cash.

**Possible exceptions**
- Sale is later cancelled or refunded after commission was calculated or
  paid — **clawback rule is not yet decided.**
- Split commission across multiple salespeople.
- Disputed commission amount.

**Automation opportunities**
- Automatic commission calculation on the decided trigger event.
- Automatic routing for approval with configurable thresholds.
- Commission statements/reporting for sales staff.

---

## F. Purchase → Purchase Order → Goods Receipt → Supplier Invoice → Payment

**Trigger**
A need to purchase goods or services is identified (e.g. stock reorder,
hardware needed for a confirmed sale, general procurement need).

**Steps**
1. Purchase requisition/request is raised in Purchasing.
2. Purchase Order is created and sent to the supplier — **value-based
   approval** (CONFIRMED, PUR-001): below a threshold, procurement/
   finance approve directly; above it, Dennis approves (exact threshold
   not yet specified).
3. Goods (or services) are received; Goods Receipt is recorded in
   Purchasing, updating Inventory (and Hardware Management, for
   serialized hardware). Note: per PUR-002, the Goods Receipt is not
   used for invoice matching — see step 4.
4. Supplier sends an invoice; it is recorded in Accounts Payable and
   matched against the PO only (**2-way matching**, CONFIRMED, PUR-002 —
   no separate goods-receipt match).
5. Supplier invoice is **auto-approved for payment once it matches the
   PO** (CONFIRMED, PUR-003); a mismatch is handled as an exception
   (handling not yet decided).
6. Payment to the supplier is scheduled and recorded.

**Responsible user/department**
Procurement/purchasing staff (requisition, PO); warehouse/hardware staff
(goods receipt); finance/accounts team (invoice matching, approval,
payment).

**Data created**
Purchase requisition, Purchase Order and lines, Goods Receipt records,
Supplier Invoice, payment records, updated Inventory/Hardware Management
records.

**Approval points**
- PO approval before sending to supplier — value-based (CONFIRMED,
  PUR-001; exact threshold not yet specified).
- Supplier invoice payment is auto-approved on a successful PO match
  (CONFIRMED, PUR-002/PUR-003); a mismatch requires human handling (rule
  not yet decided).

**Financial impact**
A liability (Accounts Payable) is created when the supplier invoice is
recorded; cash decreases when payment is made. Goods receipt affects
inventory valuation using **weighted average cost** (CONFIRMED, INV-002).

**Possible exceptions**
- Partial delivery / partial goods receipt.
- Invoice amount does not match PO/receipt (price or quantity
  discrepancy).
- Damaged/rejected goods on receipt.
- Supplier invoice received before goods, or vice versa.

**Automation opportunities**
- Auto-reorder suggestions from Inventory stock levels.
- Automated 2-way match checking (PUR-002) with exception flagging on
  mismatch.
- Payment run scheduling based on supplier terms.

---

## G. Hardware Purchase → Inventory → Delivery → Customer Installation → Asset/Serial Number

**Trigger**
Hardware is purchased (via Workflow F) for stock or for a specific
confirmed customer sale.

**Steps**
1. Hardware is received via Goods Receipt (Purchasing) and enters
   Inventory as stock.
2. For serialized hardware, individual units are registered as assets in
   Hardware Management with serial/asset numbers, linked to the goods
   receipt.
3. When sold (Sales Order), the relevant asset(s) are allocated to the
   order and linked to the customer.
4. Hardware is delivered to the customer site (Customer Management
   provides the site/address).
5. Hardware is installed; installation is recorded against the asset,
   including installation date and location.
6. **Customer signs off / confirms acceptance** (CONFIRMED, HW-001) —
   installation is not considered complete or billable until this
   happens; an engineer's own confirmation is not sufficient.
7. Asset status is updated to "installed" and linked to the customer's
   Service Contract, if the asset is covered by one.

**Responsible user/department**
Warehouse/hardware team (receipt, asset registration, delivery); field
engineers (installation); sales/customer management (customer/site
linkage).

**Data created**
Inventory stock movement records, Hardware asset records (serial-tracked),
delivery records, installation records, asset-to-customer/site/contract
links.

**Approval points**
- **Customer sign-off/acceptance is required** before installation is
  considered complete and billable (CONFIRMED, HW-001).

**Financial impact**
Inventory value moves from stock to cost-of-goods-sold upon
delivery/installation, valued at **weighted average cost** (CONFIRMED,
INV-002). Hardware sale revenue is recognized **on invoice** (CONFIRMED,
BILL-005), which per HW-001 cannot happen until the customer has signed
off.

**Possible exceptions**
- Hardware fails on installation and must be replaced (RMA process not
  yet decided).
- Delivery delayed or partial (multi-unit orders).
- Asset later relocated to a different customer/site.
- Asset returned/decommissioned.

**Automation opportunities**
- Auto-generation of an asset record from goods receipt for serialized
  items.
- Delivery/installation scheduling and notifications.
- Warranty expiry reminders.

---

## H. Invoice → Payment → Reconciliation → Outstanding Balance

**Trigger**
An invoice is issued to a customer (from Billing, arising from any of
Workflows A–G that result in billable activity).

**Steps**
1. Invoice is issued (and, in future, submitted via InvoiceNow/Peppol —
   see Integrations).
2. Invoice is recorded as an open receivable in Accounts Receivable.
3. Customer makes a payment (in full or in part).
4. Payment is recorded and allocated against the invoice(s) — **finance
   specifies the allocation manually**, based on remittance information
   from the customer (CONFIRMED, AR-001; no automatic FIFO or other
   fixed rule).
5. Payment is reconciled against bank records (manually or via future
   banking integration).
6. Outstanding balance (if any) is tracked and aged for follow-up.

**Responsible user/department**
Finance/accounts team.

**Data created**
Payment records, payment allocations, reconciliation records, AR aging
data.

**Approval points**
- Write-off of a small unreconciled difference or bad debt — **finance
  can write off small amounts directly; above a threshold, Dennis
  approves** (CONFIRMED, AR-002; exact threshold not yet specified).
- Credit note issuance if the invoice needs correction — **finance or
  Cherish approves; above a value threshold, Dennis approves**
  (CONFIRMED, BILL-003; exact threshold not yet specified — see also
  [module-map.md](module-map.md), Billing module).

**Financial impact**
Direct: reduces the customer's outstanding balance and increases recorded
cash on payment; feeds Finance / Accounting for cash position and revenue
reporting.

**Possible exceptions**
- Overpayment or underpayment by the customer.
- Payment received with no clear invoice reference.
- **Disputed invoice — continues through normal collections/aging**
  (CONFIRMED, AR-003); no automatic hold while the dispute is resolved
  internally.
- Bad debt / non-payment beyond a threshold (per AR-002).

**Automation opportunities**
- Automated payment-matching *suggestions* (e.g. by reference number/
  amount) to speed up finance's manual allocation (AR-001 keeps the
  final allocation decision manual, not automatic).
- Automated aging reports and overdue reminders.
- Bank feed integration for reconciliation (future, via Integrations).

---

## I. Contract Expiry → Renewal Opportunity → Renewal Quotation → New Contract

Status: the treatment of unused hours at expiry (step 6), the pre-expiry
lead time, and the renewal record mechanics and backdating window
(step 5) are **CONFIRMED** per SRV-005, SRV-006, SRV-010, SRV-014, and
SRV-016 in
[business-requirements.md](business-requirements.md#service-operations-business-rules-confirmed).
The rest of this workflow remains proposed/conceptual.

**Trigger**
A Service Contract approaches its expiry date — the pre-expiry check
begins **30 days before expiry** (CONFIRMED, SRV-014).

**Steps**
0. **Pre-expiry accounting check (CONFIRMED requirement, SRV-006/SRV-014)**
   — starting 30 days before a contract expires, the system identifies:
   open job orders, missing service records, unapproved excess hours,
   billable excess hours not yet invoiced, and other service activities
   requiring review. The goal is that at expiry, all service activities
   are accounted for (billable items processed; remaining hours expired).
1. Approaching expiry is detected (based on contract end date) and a
   renewal Opportunity is created/flagged in CRM.
2. Sales reviews the account (usage, satisfaction, any issues) ahead of
   renewal discussion — **what information must be reviewed before
   renewal is not yet decided.**
3. A renewal Quotation is prepared in Sales (may repeat prior terms or
   propose changes).
4. Customer accepts the renewal quotation.
5. A **new Contract record** is created (status **Draft**, then
   **Active** per SRV-001), referencing the prior contract for history
   (CONFIRMED, SRV-010) — not an extension of the existing record. Its
   start date is **backdated to immediately follow** the prior
   contract's expiry, so there is no coverage gap, **as long as renewal
   happens within 2 weeks of expiry** (CONFIRMED maximum window,
   SRV-016). Beyond 2 weeks, the renewal is not eligible for backdating
   and is treated as a fresh, non-contiguous contract (handling for that
   case not yet decided). It receives its own **new support-hour
   allocation** — it does not inherit the expiring contract's remaining
   balance (CONFIRMED,
   SRV-005).
6. Prior contract moves to **Expired** status (SRV-001). Any unused
   contracted hours on it **expire completely** (CONFIRMED, SRV-005):
   they do not carry forward automatically, do not carry forward on
   renewal, do not convert to monetary credit, and do not transfer to
   another contract. They are recorded as an **Expired Hours Record**,
   kept visible for reporting and audit (e.g. 6 unused hours out of 20
   contracted become 6 Expired Hours; the renewal starts its own fresh
   allocation).

**Responsible user/department**
Sales (renewal opportunity and quotation); contract administration
(contract closeout/activation); service operations / Nico, or Cherish as
backup (pre-expiry accounting check per SRV-006/SRV-011); Customer
Management (account status).

**Data created**
Pre-expiry review findings (open job orders, missing service records, unapproved/
unbilled excess), Renewal Opportunity, renewal Quotation, new Contract
record with its own allocation, Expired Hours Record for the prior
contract.

**Approval points**
- Renewal terms approval, especially if pricing changes materially — not
  yet decided.
- Sign-off that the pre-expiry accounting check (SRV-006) is complete
  before the prior contract is allowed to close out — approver not yet
  decided, though Nico (or Cherish as backup) is the confirmed reviewer
  for any excess usage found (SRV-004/SRV-011).

**Financial impact**
No immediate financial transaction at expiry itself, beyond any billable
excess usage invoiced as part of the pre-expiry accounting check
(SRV-006). Expired unused hours have no financial value (per SRV-005 —
they do not convert to credit). The renewed contract resumes recurring
billing (Billing) once active. A renewal within **2 weeks** of expiry is
backdated to avoid a service gap (CONFIRMED maximum window, SRV-016);
beyond that, it is not backdated and handling is not yet decided.

**Possible exceptions**
- Customer does not renew (churn) — contract lapses; unused hours still
  expire per SRV-005, but **what happens to open job orders with no
  successor contract is not yet decided.**
- Renewal negotiated with materially different terms.
- Renewal delayed past the expiry date but within the **2-week**
  backdating window (SRV-016) — still treated as seamless per SRV-010.
  Beyond 2 weeks, the renewal is a non-contiguous contract and whether a
  real coverage gap exists (and how any service activity in it is
  handled) is not yet decided.
- Pre-expiry check finds unresolved items (e.g. unapproved excess hours)
  that cannot be closed out before the expiry date — escalation path not
  yet decided.

**Automation opportunities**
- Automated renewal reminders starting 30 days before expiry (SRV-014).
- Automated pre-expiry scan for open job orders, missing service records, and
  unapproved/unbilled excess hours (SRV-006), surfaced on the Service
  Operations dashboard.
- Auto-drafting a renewal quotation from the expiring contract's terms.
- Automatic creation of the Expired Hours Record at expiry, and of the
  new contract's fresh allocation on renewal.
- Dashboard of upcoming contract expiries (Reporting).
