# Business Requirements

Placeholder document.

This file will contain the detailed business requirements for Websoft Service ERP Solution,
covering the business areas listed in the root [CLAUDE.md](../CLAUDE.md)
(CRM, Sales, Customer Management, Service Contracts, Helpdesk, Service
Operations, Projects, Service Records, Billing, Accounts Receivable, Accounts
Payable, Purchasing, Inventory, Hardware Management, Commission Management,
Management Reporting, AI Assistant).

No business workflows or rules have been defined yet. Detailed requirements
will be gathered and documented here before any application coding begins.

## Project-Level Requirements (Approved)

These are project-level requirements approved alongside the architecture
decisions in the root [CLAUDE.md](../CLAUDE.md). They are not detailed
business workflows — those are still to be gathered per business area —
but they set boundaries that the detailed requirements and architecture
must respect.

### Odoo replacement strategy

- Websoft Service ERP Solution will replace Odoo through a **phased, module-by-module
  replacement**, not a big-bang cutover.
- Each replaced module will go through a **parallel-run period** alongside
  the corresponding Odoo module before Odoo is retired for that module.
- The order in which business areas are phased in has not yet been decided.

### Historical data from Odoo

- Important historical data currently in Odoo will eventually need to be
  migrated into Websoft Service ERP Solution.
- Not all historical data needs to remain fully operational — older data
  may be migrated into an **archival** form rather than into live,
  actively-used records.
- Which data is "important", which is archival-only, and the exact
  migration scope/order have not yet been decided.

### Multi-company

- The initial implementation is for **Webmaster Consultancy Pte Ltd only**.
- Business requirements and workflows should be captured in a way that does
  not assume a single company is hard-coded forever, since the system is
  expected to support multiple companies/entities in the future.
- No specific additional companies/entities have been identified yet.

### Singapore regulatory and operational requirements

The following must be anticipated by both business requirements and
architecture, even though detailed workflows are not yet defined:

- **GST** — Goods and Services Tax handling (e.g. on invoices, billing).
- **InvoiceNow / Peppol** — Singapore's e-invoicing network.
- **PDPA** — Personal Data Protection Act compliance for personal data
  handling.
- **Financial audit trails** — required for financial and operational
  transactions (see also the Development Rules in [CLAUDE.md](../CLAUDE.md)).
- **Role-based access control** — required for authentication/authorization
  across the system.
- **Data backup and recovery** — required as an operational capability.

These are constraints to design for, not yet fully specified requirements.
Detailed rules for each (e.g. GST rates/treatment, specific PDPA data
handling procedures) are still to be gathered.

## Service Operations Business Rules (CONFIRMED)

Status: **CONFIRMED / DECIDED** — first round 2026-09-04 (SRV-001–006),
second round 2026-09-06 (SRV-007–015). Unlike most of this document, the
following rules are finalized and are authoritative for Service
Contracts, Helpdesk / Service Operations, Service Records, and Billing. They
supersede the corresponding open items in
[open-business-decisions.md](open-business-decisions.md), which has been
updated to reflect that — see that document for what remains open.

### SRV-001 — Standard Service Contract Duration — CONFIRMED

- The standard service contract duration is **12 months**.
- Every contract stores a **start date** and an **expiry date**.
- A contract's lifecycle is tracked through the following states:
  **Draft → Active → Exceeded (if applicable) → Expired / Renewed.**
  "Exceeded" reflects a contract that is still within its 12-month period
  but has consumed all of its contracted hours (see SRV-003/SRV-004)
  ahead of expiry.

### SRV-002 — Minimum Contracted Support Hours — CONFIRMED

- The minimum support hours for a normal service contract is **10
  hours**.
- The system must prevent creation of a normal service contract with
  fewer than 10 hours.
- **No override mechanism exists** — 10 hours is a hard minimum, with no
  exceptions, until Dennis decides otherwise (CONFIRMED, SRV-012).

### SRV-003 — No Grace Period — CONFIRMED

- There is **no grace period** for exceeding contracted support hours.
- Once contracted hours are fully consumed, the next unit of support
  usage is immediately **excess usage** — it is not automatically
  absorbed as free/bonus hours.

### SRV-004 — Excess Hours Require Nico's Review — CONFIRMED

- Once a customer reaches or exceeds contracted support hours, further
  support usage must be reviewed by **Nico**, who is responsible for
  Service & Support.
- The system must **not** simply continue deducting excess usage
  automatically from the contract.
- A contract's usable balance can **never become negative**.
- Excess usage is recorded **separately** from normal contract
  consumption and must be clearly visible.
- Nico decides the treatment of each instance of excess usage — for
  example: billable excess support, approved non-billable support, or
  another authorized treatment to be defined later.
- Every such decision, and the reason for it, must be **auditable** (who
  decided, when, what was decided, and why).
- When Nico is unavailable, **Cherish (Sales Manager)** is the confirmed
  backup/delegate reviewer for excess usage (CONFIRMED, SRV-011).

### SRV-005 — Unused Hours Expire Completely — CONFIRMED

- At the end of the 12-month contract period, all unused contracted
  support hours **expire completely**.
- Unused hours do **not**: carry forward automatically, carry forward
  upon renewal, convert into monetary credit, or transfer to another
  contract.
- A renewal creates a **new support-hour allocation** — it does not
  inherit or extend the expiring contract's remaining balance.
- Historical expired hours must remain **visible for reporting and
  audit purposes** (archived, not deleted).

  *Example:* a contract with 20 contracted hours, of which 14 were used,
  has 6 hours remaining at expiry. Those 6 hours become **Expired
  Hours**; the usable balance becomes 0; the renewal contract receives
  its own new allocation.

### SRV-006 — No Unbilled Service Hours — CONFIRMED

- The system must not allow normal service activity to remain
  indefinitely in an "Unbilled Hours" state. Every completed service
  activity must ultimately be accounted for as exactly one of:
  1. Contract hours consumed — no additional invoice required.
  2. Excess hours approved by Nico as billable — must proceed to
     billing/invoicing.
  3. Approved non-billable excess — the reason must be recorded.
  4. Another explicitly approved treatment.
- The system monitors for **"Unaccounted Service Activity"** rather than
  treating unbilled hours as an acceptable permanent state.
- Before a service contract expires, the system should identify: open
  job orders, missing service records, unapproved excess hours, billable
  excess hours not yet invoiced, and other service activities requiring
  review.
- The objective at contract expiry is: **all service activities
  accounted for** — all billable service items processed, and any
  remaining contracted hours expired (per SRV-005).

SRV-001 through SRV-006 directly resolve open items 1.2 and 1.3 in
[open-business-decisions.md](open-business-decisions.md). A second round
of decisions (SRV-007 through SRV-015 below, confirmed 2026-09-06)
resolves the remaining Service Operations items from that document: 1.1,
1.4, 1.5 (SLA — since removed altogether, 2026-09-15), 1.6, 1.8, 1.9,
1.10, 1.11, and 9.3. A third round (SRV-019 and SRV-020, confirmed
2026-09-15) resolves 9.1 and 9.2.

### SRV-007 — Hour Rounding — CONFIRMED

- Each service record logged against a contract is **rounded up to the
  nearest 15 minutes** before it is deducted from the contract's usable
  balance (e.g. 23 minutes logged deducts 30 minutes; 5 minutes logged
  deducts 15 minutes).
- This rounding applies before the SRV-003 "no grace period" check — i.e.
  the rounded amount is what is compared against the remaining balance.

### SRV-008 — Excess Hour Billing Rate — CONFIRMED

- When Nico (or Cherish, per SRV-011) approves excess usage as billable,
  it is charged at the **contract's own blended rate** — the contract's
  total value divided by its contracted hours — not a separate flat or
  premium overage rate.
- **No customer pre-approval is required** before invoicing billable
  excess usage: the customer is invoiced and then notified, not asked to
  approve in advance.

### SRV-009 — SLA Targets — REMOVED (2026-09-15)

- Originally a confirmed deferral ("no formal SLA targets at this
  time"). On 2026-09-15 Dennis removed it outright: **no SLA
  response/resolution targets, and none are planned** — "not necessary
  to have". Job Order priority and timestamps remain, as ordinary
  operational data, not as the groundwork for SLA tracking.

### SRV-010 — Renewal Record & Coverage Continuity — CONFIRMED

- A contract renewal creates a **new Contract record** (not an extension
  of the expiring one), consistent with SRV-005's "new support-hour
  allocation." The new record references the prior contract for
  continuity/history.
- The new contract's start date is **backdated to immediately follow**
  the prior contract's expiry date, so there is **no coverage gap**
  between an expiring and a renewed contract, provided renewal happens
  within **2 weeks** of expiry (CONFIRMED, SRV-016).
- Beyond that 2-week window, a renewal is **not** eligible for seamless
  backdating and is instead treated as a fresh, non-contiguous contract
  — how that case is handled (e.g. whether a coverage gap exists, and
  what happens to any service activity in that gap) is not yet decided.

### SRV-011 — Backup Reviewer for Excess Usage — CONFIRMED

- **Cherish (Sales Manager)** is the confirmed backup/delegate reviewer
  for excess usage decisions (per SRV-004) when Nico is unavailable.
- The same requirements apply to Cherish's decisions as to Nico's: the
  treatment decision and reason must be recorded and auditable.

### SRV-012 — No Override for Minimum Hours — CONFIRMED

- There is **no override mechanism** for the SRV-002 minimum of 10
  contracted support hours.
- 10 hours is a **hard minimum** for a normal service contract; the
  system must block creation below it with no exceptions, until Dennis
  decides otherwise.

### SRV-013 — Additional Excess Usage Treatment Categories — CONFIRMED

- Beyond "billable excess support" and "approved non-billable support"
  (SRV-004), two further treatment categories are confirmed:
  - **Warranty / Goodwill** — excess work performed as a goodwill
    gesture or to address a product/service issue; not billed, reason
    recorded as goodwill.
  - **Internal Write-off** — excess work absorbed as an internal cost
    (e.g. an estimation miss or staff error); not billed, reason
    recorded as a write-off.
- As with every other treatment under SRV-004, the choice of category and
  the reason for it must be recorded and auditable.

### SRV-014 — Pre-Expiry Review Lead Time — CONFIRMED

- The SRV-006 pre-expiry accounting check (open job orders, missing
  service records, unapproved/unbilled excess) begins **30 days before** a
  contract's expiry date.

### SRV-015 — Service Record Submission Timeframe — CONFIRMED

- Staff must submit service records for work performed **within 3 business
  days** of doing the work.
- A service record not submitted within that window is flagged as a
  **missing service record** — feeding both the SRV-014 pre-expiry check and
  the Service Operations dashboard.

### SRV-016 — Maximum Renewal Backdating Window — CONFIRMED

- The maximum window after a contract's expiry within which a renewal
  still qualifies for seamless, backdated coverage (per SRV-010) is
  **2 weeks**.
- A renewal confirmed within 2 weeks of expiry is backdated to
  immediately follow the prior contract's expiry, so there is no
  coverage gap.
- A renewal that happens **more than 2 weeks after expiry** is **not**
  eligible for this backdating — it is instead treated as a fresh,
  non-contiguous contract (see SRV-018).

### SRV-017 — No Customer Credit Check Required — CONFIRMED

- A customer credit check or credit limit is **not required** before
  activating a new service contract, for now.
- Contracts activate based on the commercial/sales agreement alone; this
  may be revisited later if collections experience warrants it.

### SRV-018 — Late Renewal (Beyond 2-Week Window) — CONFIRMED

- A renewal that happens more than 2 weeks after the prior contract's
  expiry (i.e. outside the SRV-016 window) is **not** automatically
  backdated.
- There is no fixed rule for this case: it is handled **case-by-case**,
  at the discretion of Nico, Cherish, or Dennis, depending on
  circumstances (e.g. whether to treat it as a true coverage gap, or
  make a judgment call to backdate anyway).

### SRV-019 — Service Record Approval: Nico or Cherish, within a week — CONFIRMED (2026-09-15)

- Only **Nico (Service Lead)** and **Cherish (Sales Manager)** may
  approve a submitted Service Record and key in the deducted hours.
  Nobody else — including the owner — can approve one. (Resolves open
  item 9.1; the earlier build let the owner approve as a placeholder.)
- A submitted record is to be approved **within a week** (7 days) of
  submission. Past that it is flagged **approval overdue** on the
  Service Record Approval queue and counted on the Company Dashboard.
  Nothing is auto-approved and nothing is blocked — the flag is a
  prompt, not an enforcement (pragmatic default: Dennis gave the
  deadline, not a consequence).
- **Daily reminder email at 8:45 am** (Dennis, 2026-09-26, decision
  9.1 / #50: "Flag + email the approvers", "Add a timer to the
  server"). On any day with overdue records, each active Service Lead
  and Sales Manager gets one email listing them (record, Job Order,
  customer, engineer, days waiting). It is sent from the system
  mailbox, and nothing goes out on a day with none overdue. Each send,
  or skip, is recorded in Event Logs. The server's `scheduler` service
  runs it.
- **On eApproval** (Dennis, 2026-09-26, decision page): the approvers
  are the **Service Record Approval** authority under eApproval Master --
  Nico and Cherish, **any one** of them decides -- set up for each
  company from the two roles, and changeable there like any authority.
  Each record goes to them as it is submitted (or timed out on the
  phone), with an **email as it arrives**, and shows in their Approval
  Center, which links to the Service Record Approval screen: the hours
  to deduct are keyed in there. An approver can also **Reject** a
  record, **with a reason**; it then counts nowhere and stays on file,
  and the engineer logs a new one.
- Excess-usage review (SRV-004 / SRV-011) is unchanged by this rule:
  it is about the treatment of excess hours, not the approval of the
  record.

### SRV-020 — Billable / Non-billable / Contract-covered follows the Job Order — CONFIRMED (2026-09-15)

- Whether logged time is contract-covered, billable or non-billable
  **depends on the Job Order context**, never on a choice made by the
  staff member logging the record. (Resolves open item 9.2.)
- Every Job Order carries a **billing classification**, set when it is
  raised and correctable while it is still open:
  - **Contract hours** (default, the original behaviour): the linked
    contract's balance decides — SRV-003 deduction while hours remain,
    Excess Usage for Nico's review beyond that (SRV-004); an Annual or
    Ad Hoc contract meters nothing.
  - **Billable**: chargeable work outside the contract's hour pool.
    Nothing is deducted; the approved record is marked *billable* and
    is charged through the Job Order's own billing — a milestone /
    fixed-price invoice for a project (BILL-004) or a Sales Invoice
    raised by hand. No invoice is raised automatically (pragmatic
    default — SRV-006's "no unbilled service hours" still applies, and
    the Operations Reports can list every billable record by outcome).
  - **Non-billable**: internal, warranty or goodwill work. Nothing is
    deducted and nothing is billed; the record is kept for the audit
    trail and reports.
- Changing a Job Order's classification affects only records approved
  from then on; a contract deduction already made is never rewritten.

### Service Operations Workflow (CONFIRMED shape)

The end-to-end service workflow these rules govern is:

```
Customer
  → Job Order
  → Assignment
  → Service Work
  → Service Record
  → Contract Hour Validation
  → Contract Deduction OR Excess Review (Nico)
  → Billing Decision
  → Invoice (if billable)
  → Complete / Auditable Record
```

See [workflows.md](workflows.md), Workflow C, for the detailed version of
this workflow, including responsible parties, data created, and
exceptions.

### Service Operations Dashboard Requirements

The Service Operations dashboard should eventually be able to identify:

- Active contracts
- Contracts expiring soon
- Contracted hours
- Used hours
- Remaining usable hours
- Expired hours
- Excess hours
- Excess hours awaiting Nico's review
- Missing service records
- Open job orders
- Service activities requiring accounting/billing action
- Renewals required

This is a requirements list, not a design — see
[module-map.md](module-map.md) (Reporting / Management Dashboard) for
where this is expected to live architecturally.

## Billing & Invoicing Business Rules (CONFIRMED)

Status: **CONFIRMED / DECIDED** (2026-09-09). These resolve items 2.1–2.6
in [open-business-decisions.md](open-business-decisions.md).

### BILL-001 — Recurring Contract Billing Cycle — CONFIRMED

- Service contracts are billed **annually upfront**: the full 12-month
  contract value is invoiced at contract start (and at each renewal),
  matching the SRV-001 12-month term. There is no monthly/quarterly
  recurring billing cycle for standard contracts.

### BILL-002 — Invoice Approval — CONFIRMED

- **No approval is required** before an invoice is issued to a customer.
  System-generated invoices (e.g. annual contract billing, hardware
  sales) are issued directly.

### BILL-003 — Credit Note Approval — CONFIRMED

- Credit notes are approved by **finance or Cherish (Sales Manager)** for
  routine cases.
- Credit notes above a value threshold are **escalated to Dennis** for
  approval. **Settled 2026-09-26:** the limit is set per customer, on
  its Company / Individual file ("Credit Note limit should be set in the
  company/individual file"); with none set, the owner approves every
  credit note.
- **Built 2026-09-26.** A credit note is raised from its row on the
  Sales Invoice page, with a reason and a net amount; GST follows the
  invoice's own tax code and rate. It waits on Accounts → Credit Note
  until someone allowed approves or rejects it; either way it is kept.
  Approving issues it: a CN number (issued ones number without gaps),
  the invoice's ledger entry posted in reverse (Dr revenue, Dr 2100 GST
  output / Cr 1100 AR, under the Sales Invoice period locks), the total
  taken off what the invoice owes, and it counts in the GST Calculation
  as negative output tax in its tax code's box. An invoice credited in
  full reads "Credited". Word and PDF print, CSV / Excel export.
  Defaults taken are in
  [open-business-decisions.md #52](open-business-decisions.md#52-credit-note-defaults-taken-raised-and-built-2026-09-26).
- **Follow-ups answered and built 2026-09-26** (decision page):
  - **Always against one Sales Invoice**, for **whole lines, part
    quantities or an amount**. A line's quantity is limited to what
    was sold less what other credit notes already took.
  - **Goods returned**: tick it per stock line and pick the warehouse;
    issuing the credit note puts them back **at the cost they left
    at**.
  - **Approval**: within the customer's credit note limit it is
    **issued straight away** by whoever raises it (EDIT on Billing);
    above the limit, or with none set, it waits for the **owner**.
    (This replaces "approved by Finance or Cherish" above.)
  - **A paid invoice can be credited**: what the invoice no longer
    owes stays on the customer's account as credit. **Finance
    chooses**: set it against another of their invoices (same
    currency), or **refund it with a Payment Voucher** (Dr AR / Cr
    bank; Bank Authority approval and the Bank step as for any PV).
    Commission already earned on the paid part is taken back, as on
    a write-off.
- **Separate from the customer's credit limit** (Dennis, 2026-09-26:
  "2 separate matter and settings, all in company/individual file"):
  the **credit limit** is the most a customer may owe at once, also on
  the Company / Individual file, shown there against what it owes now
  with an "Over credit limit" flag. **Exceeding it only warns**
  (Dennis, 2026-09-26, decision #48): picking a customer that owes more
  than its limit -- or that a new Sales Invoice would take over it,
  counting the lines before GST -- on the quotation, Sales Invoice or
  Job Order form shows a warning, and staff can carry on. Nothing is
  blocked and no approval is needed.

### BILL-004 — Project Billing Method — CONFIRMED

- Projects (as distinct from Service Contracts) are billed on a **fixed
  price / milestone** basis — billed amounts are tied to project
  milestones, not to actual hours logged (i.e. not time-and-materials).

### BILL-005 — Revenue Recognition — CONFIRMED

- Revenue is recognized **on invoice** — i.e. when the invoice is issued
  — for contracts, projects, and hardware sales alike. **Confirmed again
  2026-09-26 for annual upfront contracts** ("When invoiced, take the
  revenue accordingly, don't need spread"): nothing is deferred or
  released monthly, so the seeded 2300 Deferred revenue account stays
  unused.

### BILL-006 — Quotation Approval — CONFIRMED

- **Cherish (Sales Manager) approves all quotations** before they are
  sent to a customer — there is no discount/value threshold that
  exempts a quotation from this review; every quotation goes through
  Cherish.

## Accounts Receivable Business Rules (CONFIRMED)

Status: **CONFIRMED / DECIDED** (2026-09-09). These resolve items 3.1–3.3
in [open-business-decisions.md](open-business-decisions.md).

### AR-001 — Payment Allocation — CONFIRMED

- When a customer payment does not exactly match one invoice, or covers
  multiple invoices, **finance specifies the allocation manually** (based
  on remittance information from the customer) — there is no automatic
  FIFO or other fixed allocation rule.

### AR-002 — Write-off / Bad Debt Process — CONFIRMED

- ~~**Finance can write off small amounts directly.** Write-offs above
  a threshold require Dennis's approval.~~ **Changed 2026-09-26:** "I
  think can totally remove this write off approval amount." There is no
  amount. **The owner and Finance write off, Finance without the
  owner's approval** (confirmed the same day: "Yes that's what I
  meant"); anyone else is refused. Always with a reason. The Company
  Setup threshold is removed.
- **Posting, settled 2026-09-26** ("It has to be expenses account"): a
  write-off posts Dr **6700 Bad debts written off** (an Expense account)
  / Cr 1100 Accounts receivable for the outstanding amount, as a journal
  voucher dated the day of the write-off. GST bad-debt relief is a
  separate IRAS claim this does not make.

### AR-003 — Disputed Invoice Handling — CONFIRMED

- A disputed invoice **continues through normal collections/aging** —
  there is no automatic hold on reminders or collection activity while a
  dispute is being resolved internally.

## Purchasing & Accounts Payable Business Rules (CONFIRMED)

Status: **CONFIRMED / DECIDED** (2026-09-09). These resolve items 4.1–4.3
in [open-business-decisions.md](open-business-decisions.md).

### PUR-001 — Purchase Order Approval — CONFIRMED

- PO approval is **value-based**: purchase orders below a threshold can
  be approved by procurement/finance staff directly; above the threshold,
  Dennis's approval is required. **Settled 2026-09-26:** the limit is set
  per supplier, on its Company / Individual file ("Purchase Order Limit
  … should be set in the company/individual file"), replacing the single
  Company Setup threshold. With no limit set on a supplier, the owner
  approves every PO to it.
- **On eApproval** (Dennis, 2026-09-26): within the supplier's limit
  nothing changes. Above it (or with no limit set) the PO goes to the
  **eApproval approvers** -- the authority with a Purchase Order rule
  under eApproval Master -- who are emailed as it arrives and decide in
  the Approval Center: approved, the PO is approved; rejected, it is
  cancelled with their reason. With no such authority set up, the owner
  approves as before.

### PUR-002 — Supplier Invoice Matching — CONFIRMED

- Supplier invoices use **2-way matching**: the invoice is matched
  against the Purchase Order only — a separate Goods Receipt match is
  not required.

### PUR-003 — Supplier Invoice Approval — CONFIRMED

- A supplier invoice is **auto-approved for payment once it matches** the
  PO (per PUR-002) — no separate manual approval step is required beyond
  that match succeeding.
- **A different amount is not an exception — settled 2026-09-26** ("I
  order 10, but delivery came 20… the bill is based on 20… pay based on
  20 is ok"). The bill is approved and paid on the billed amount, and
  the difference is written into its match note. Only a bill from a
  different supplier, or against a purchase order that is not approved,
  is held as an exception; a bill flagged before this rule can be
  matched again from Accounts Payable.

### PUR-004 / INV-003 — Stock movements do not post to the General Ledger — CONFIRMED (2026-09-26)

- Dennis: "No movements don't go the GL. They will do their own journal
  adjustment on a monthly closing basis." Goods receipts, issues,
  transfers, returns and adjustments move stock quantities and average
  cost only; the inventory / cost-of-goods figures are brought into the
  General Ledger by Finance's own month-end Journal Voucher.

## Inventory & Hardware Business Rules (CONFIRMED)

Status: **CONFIRMED / DECIDED** (2026-09-09). These resolve items
5.1–5.3 in [open-business-decisions.md](open-business-decisions.md).
Items 5.4 (RMA process) and 5.5 (warranty terms) remain open.

### INV-001 — Stock Adjustment Approval — CONFIRMED

- Stock adjustments (for discrepancies, damage, loss, etc.) **require
  manager approval** before taking effect — warehouse/hardware staff
  cannot adjust stock unilaterally.

### INV-002 — Inventory Valuation Method — CONFIRMED

- Inventory is valued using **weighted average cost**: the cost per unit
  is the average cost of all units currently in stock.
- **The average is held once per item, across all locations and
  branches** (CONFIRMED 2026-09-15, revising the original per-warehouse
  implementation). On receipt:
  `new_avg = (total_qty_all_locations × existing_avg + received_qty ×
  received_cost) ÷ (total_qty_all_locations + received_qty)`.
  The Stock Master item detail shows this **Avg Cost** and the extended
  **Cost Value** of everything on hand at it.
- A **Goods Transfer moves quantity between locations only** — it never
  affects cost. With a single company-wide average this is true by
  definition rather than by a special rule.
- **A Goods Issue Note and a stock-picking Sales Invoice deduct at the
  average cost**, which the deduction itself never moves.
- **Neither quantity nor cost may go negative.** A deduction larger than
  what is held at that location is refused outright (never a partial
  issue), and stock at another branch does not make the shortfall good.
  A receipt at a negative unit cost is refused at entry — with no
  negative quantity and no negative receipt cost, a negative average is
  unreachable.
- **Every stock movement records the running balance it produced**
  (quantity, average cost and cost value after it), so a later
  recalculation can be tallied back against history.
- A **stock adjustment that increases quantity may carry its own unit
  cost** and re-weight the average like a receipt, for opening balances
  and found stock. Omitting the cost keeps it a pure count correction
  that reuses the current average and moves no weighting.

### HW-001 — Hardware Installation Sign-off — CONFIRMED

- Hardware installation is **not considered complete (or billable)**
  until the **customer signs off / confirms acceptance** — internal
  confirmation by the installing engineer alone is not sufficient.


## Accounting Posting Business Rules (CONFIRMED)

Status: **CONFIRMED / DECIDED** (2026-09-14). Full design in
[gl-posting-design.md](gl-posting-design.md). Background: as of commit
`8ac396b` no sub-ledger document posted to the General Ledger at all.

### ACC-001 — Sub-ledger Posting Scope — CONFIRMED

- Sales Invoices, Supplier Bills, Receipt Vouchers and Payment Vouchers
  **all post to the General Ledger**; receipts and payments also write
  to the bank book. Chosen over "bank book only" because posting
  receipts alone would drive the AR control account negative — nothing
  had ever debited it.

### ACC-002 — Bank Step — CONFIRMED

- Money is entered in the bank book by an **explicit "Bank" action**,
  reversible by **"Unbank"** — the `BANK` / `UNBANK` operations already
  declared in the period-lock matrix. Recording a voucher and confirming
  the money actually moved are separate steps.
- **Bank Authority** (Dennis, 2026-09-26, decision page): a Bank
  Authority is the **signatories of one bank account**, set under
  eApproval Master (the authority's bank account), with **"any one"** or
  **"all must"** approve. A Payment Voucher paid from that account at or
  above the amount on its Payment Voucher rule waits for the
  signatories -- emailed as it arrives, decided in the Approval Center --
  and **cannot be banked** until approved; below the amount it goes
  straight. A rejected PV cannot be banked (Finance reverses it with
  UNGL).

### ACC-003 — GL Posting Trigger — pragmatic default

- GL entries post **automatically** at each document's accounting event:
  invoice issued (consistent with BILL-005), bill approved, receipt or
  payment saved. `UNGL` reverses. Called out as a default, not a
  decision: it can become an explicit "Post" action later with no
  schema change.

### ACC-004 — Reversal, Never Deletion — CONFIRMED

- Un-posting creates a **reversing journal entry**; un-banking **voids**
  the bank transaction with a required reason. No financial record is
  ever deleted (CLAUDE.md).

### ACC-005 — No Direct Bank Book Keying — CONFIRMED

Dennis, 2026-09-26 (open-business-decisions.md #49, item 31.1): "Should
not allow them to key direct, have to key in through receipt or
payment." Built 2026-09-26.

- **The Bank Book takes no lines keyed straight into it.** Every line
  comes from a Receipt Voucher or Payment Voucher's Bank step (ACC-002),
  so every bank line has its General Ledger entry. A line keyed directly
  never reached the General Ledger, and the two drifted apart.
- **Bank interest, bank charges and the like are an "Other" receipt or
  payment.** These are made against a GL account instead of a
  Company / Individual, with a description of what they are.
  - An Other receipt posts Dr bank / Cr that account.
  - An Other payment posts Dr that account / Cr bank.
  - Neither settles invoices or bills.
  - Numbering (RV / PV), Bank / Unbank and UNGL are the same as any
    voucher.
- **Which accounts are allowed.** Any active account of the company,
  except:
  - the control accounts (1100 AR, 2000 AP, 2100 / 2110 GST), whose
    balances must come only from their documents;
  - a bank's own account. Money between two banks is a transfer, which
    is not built.
- Lines keyed before this stay in the Bank Book, and can still be voided
  and reconciled.
- **Default taken, for Dennis to confirm** (open-business-decisions.md
  #51): an Other receipt or payment carries no GST. So bank interest
  received is not counted in the GST Calculation as an exempt supply.

### ACC-006 — Multi-currency — CONFIRMED and built (2026-09-26)

- **Sales and purchases in any currency** (#49): Quotations, Sales
  Invoices, Credit Notes, Receipts, Purchase Orders, supplier bills and
  Payment Vouchers each carry a currency and an exchange rate ("1 unit
  = X SGD"), and keep every figure twice: in that currency and in SGD.
  The General Ledger, every report and the GST return read the SGD.
- **The currency** starts as the Company / Individual's own **default
  currency** (set on its file; blank = SGD) and can be changed on the
  document.
- **The rate** starts as the **Currency Rate Table**'s latest active
  rate on or before the document date, and can be changed on the
  document. A foreign currency with no rate there, and none keyed, is
  refused. A PO's bill and an accepted quotation's invoice take the
  table's rate on their own date (else the original document's).
- **GST** on a foreign-currency document is worked out in its currency
  and kept in SGD at its rate. A credit note is always in its invoice's
  currency and at its rate, so it reverses exactly what was posted.
- **Exchange gain / loss is booked only when paid** (realised; no
  month-end revaluation): a receipt or payment settles documents in its
  own currency only; allocating it clears the invoice or bill at the
  invoice's or bill's rate, and the difference to the receipt's or
  payment's own SGD value is posted to **6800 Exchange (gain) / loss**
  (a gain credits it, a loss debits it), dated the receipt or payment
  date. The last allocation of a document takes exactly what is left in
  SGD, so no cent is stranded.
- **Bank interest and GST** (Dennis, 2026-09-26, decision page): an
  Other Receipt can be ticked **exempt supply (ES)**; bank interest so
  marked counts in the GST Calculation's exempt supplies box (box 3)
  in the month received. Nothing changes the tax payable; unticked
  Other receipts stay out.
- **Foreign-currency bank accounts**: a bank account's currency (e.g.
  USD) runs its Bank Book in that currency with the SGD value beside
  each line, and takes only vouchers in that currency; a foreign-
  currency voucher can also go through an SGD account (converted).

## Customer Portal Business Rules (CONFIRMED)

Status: **CONFIRMED / DECIDED** (2026-09-14). Full design in
[customer-portal-design.md](customer-portal-design.md).

### PORTAL-001 — Login Identity — CONFIRMED

- **Each Contact person** at a customer gets their own portal
  credential, enabled per contact by staff. Not a shared login per
  Company/Individual — so there is an audit trail of who did what.

### PORTAL-002 — First-Version Scope — CONFIRMED

- Customers can **view** their contracts and hour balance, job orders,
  service records and incidents, and **raise Incidents** into the
  existing Helpdesk queue.

### PORTAL-003 — Identity Separation — pragmatic default

- Portal users live in their own table, never in staff `users`, and
  carry a purpose-tagged token that staff endpoints reject.

### PORTAL-004 — PDPA Gate — pragmatic default

- Access can be enabled only for a contact whose Company/Individual has
  given PDPA consent and is not archived; archiving a customer disables
  every portal login under it.

### PDPA-002 — AI Assistant staff data-consent gate — CONFIRMED (2026-09-15)

- Every staff user must explicitly acknowledge, once, that queries
  sent to the AI Assistant may reach Anthropic's US-hosted API (masked
  by default) and that non-sensitive usage information may be
  analysed internally by Webmaster Consultancy, before they can use
  the system at all -- a full-screen gate at login, blocking until a
  checkbox is ticked.
- Recorded exactly once, as `users.ai_data_consent_at`, the first time
  each user encounters the gate; shown read-only on Staff Master.
  **Protected**: not editable through Staff Master or any other
  screen, and the acknowledgement endpoint itself never moves an
  existing timestamp.
- Settles the outstanding half of decision 12.1 -- see
  [open-business-decisions.md #43](open-business-decisions.md#43-ai-assistant-pdpa-self-declaration-at-login-raised-and-built-2026-09-15).

### PDPA-003 — Archiving and unarchiving a Company / Individual — CONFIRMED and built (2026-09-26)

- Once its data expiry date has passed, a Company / Individual can be
  archived by anyone with FULL access; a reason is optional.
- **Before expiry** (or with no expiry date), only the **owner** can
  archive it, and a reason is required. The server enforces both.
- **Unarchiving** is for anyone with FULL access, always with a reason.
- Each archive and unarchive is written to Event Logs with its reason.

### PDPA-004 — Helpdesk Portal AI declaration — CONFIRMED and built (2026-09-26)

- Before a customer's first chat with the AI Assistant on the Helpdesk
  Portal, they tick a one-time declaration: their questions and the
  account details needed to answer them may be sent, masked, to a
  US-hosted AI provider. Recorded once per portal login
  (`portal_users.ai_data_consent_at`) and in Event Logs; the chat is
  refused until it is ticked. The portal twin of PDPA-002.

### AI-001 — AI Assistant additions (Backlog 2) — CONFIRMED and built (2026-09-26)

- **Draft with AI**: by a Service Record's work description (Job Order
  screen and the phone's Time Out), the engineer's rough notes in any
  language become a proper English description in the same box, to
  check, change and save. Nothing is saved by the assistant. Emails and
  phone numbers are masked first when masking is on. Shown only when the
  AI Assistant module is on.
- **Monthly token cap per company**: each company has its own cap under
  Maintenance → AI Assistant; the screen shows the company's use and the
  whole installation's beside it.
- **Fallback model**: when set, a call that fails at the provider or is
  declined is tried once more on it; the Usage list shows which model
  answered.

### PDPA-001 — Data expiry defaults to five years from consent — pragmatic default (2026-09-15)

- A Company/Individual's **data expiry date** lives in the PDPA & Data
  Retention section of its file, next to the consent record, not among
  the general details (Dennis, 2026-09-15).
- When PDPA consent is recorded (the e-signed date) and no expiry date
  has been set, the expiry date defaults to **five years** from the
  consent date. A date already set is never overwritten, and the
  default can be changed by hand at any time.
- The five-year figure is Dennis's instruction for the default; the
  retention period itself is not a confirmed PDPA rule, and nothing is
  purged automatically when the date passes -- the date is a prompt for
  review, per "never permanently delete important business records".

### PORTAL-005 — Invoices and Payments — CONFIRMED (2026-09-14)

- Customers can **view their own Invoices** (net, GST, total, amount
  paid, outstanding, status) and their own **Payments** (receipts) with
  which invoice(s) each one settled — the same figures as their PDF
  copy. This reverses the original PORTAL-002 "nothing financial" call,
  once Dennis asked for it explicitly. Still never shown: GST-code/rate
  internals, GP/cost figures, or anything on another customer's account.

### PORTAL-006 — Service Records by Contract — CONFIRMED (2026-09-14)

- Customers can drill from a Contract into the Service Records logged
  against it (via that contract's Job Orders) — not just the flat,
  company-wide list. Explains where the consumed hours on a
  SERVICE_SUPPORT contract actually went.

### PORTAL-007 — Customer attachments on incidents — CONFIRMED and built (2026-09-26)

- A customer can attach **photos, screenshots and PDFs** to an incident
  on the Helpdesk Portal: up to **10 MB each** and **5 per incident**,
  when raising it or later **while it is still open** (open or awaiting
  a call back).
- Staff see them as ordinary attachments on the incident (Incidents
  screen), labelled as coming from the customer. The customer sees the
  files raised through the portal, not staff's own. Every upload is in
  Event Logs.

## Helpdesk Business Rules (CONFIRMED)

### HELP-001 — Outlook Add-in acknowledgement — CONFIRMED (2026-09-15)

- When the Outlook Add-in converts an email into an Incident ("Log as
  Incident") or into a Job Order ("Convert to Job Order"), the sender
  receives an acknowledgement email from the **Helpdesk mailbox**
  (Maintenance → System Email), quoting the Incident number and, when
  a Job Order was opened, its number.
- The Helpdesk mailbox is the system's support-desk sender, separate
  from the sign-in/OTP mailbox and from each company's document
  mailbox; none of the three ever borrows another.
- The acknowledgement never blocks the conversion: if the mailbox is
  not configured or the send is refused, the Incident / Job Order are
  created exactly as they would have been and the add-in shows that no
  acknowledgement was sent. A sent acknowledgement is recorded in the
  audit trail on the Incident.
- Confirmed by Dennis 2026-09-15 ("Agreed for the response reply to
  the sender from outlook") after being built as the reading of "SMTP
  for the MS Outlook add-in to convert to Incident/Job Order".

## Sales Module Enhancements Business Rules (CONFIRMED)

Status: **CONFIRMED / DECIDED**, clarified with Dennis prior to this
build and implemented directly in `backend-php/` (new feature work, not
part of the Python→PHP conversion tracked in
[php-conversion-plan.md](php-conversion-plan.md) — see that doc's "not a
conversion" note on this set of rules). Decision record:
[open-business-decisions.md #40](open-business-decisions.md#40-sales-module-enhancements-financial-year-definition-and-contractquotation-link-raised-2026-09-22).

### SALES-001 — Job Implementation Template — CONFIRMED

- A Product/Service Catalog item carries an optional, reusable, ordered
  task checklist (its "Job Implementation Template"). One template per
  product.

### SALES-002 — Job Order Multi-Product Selection + Template Import — CONFIRMED

- A Job Order may select **more than one** Product (previously zero/one
  via Contract-level product coverage only).
- Selecting a product copies that product's Job Implementation Template
  onto the Job Order as tasks, ordered, with completion tracking gated
  to Sales Manager/Owner (same reviewer gate as PROJECT-type Job Order
  milestone completion, 7.3).
- **Pragmatic default** (dedupe, not separately confirmed): when more
  than one selected product's template names the same task, it is
  copied onto the Job Order only once (first-selected product wins) —
  see `App\Services\JobOrderImplementationTaskService` in `backend-php/`.

### SALES-003 — Contract Hour-Sharing List — CONFIRMED

- A Service Contract keeps its own list of Company/Individual customers
  allowed to draw down its pooled hours, independent of that
  Company/Individual's `CompanyIndividual` Relationships records — a
  shared-hours customer need not have any other relationship on file.
- Opening a Job Order against a contract is validated against the
  contract's own primary customer **or** this shared-hours list; neither
  match is rejected.

### SALES-004 — Contract List Filters — CONFIRMED

- The Contracts list can filter by remaining hours less than a flexible,
  caller-supplied number, and by an expiry date range on the contract's
  own end date (distinct from the pre-existing coverage-window filter,
  which finds contracts *covering* a period rather than *expiring*
  within one).

### SALES-005 — Contract Operation Report — CONFIRMED

- Two report views: **Contract Expiry Listing** (contracts expiring
  within a date range, or already expired, regardless of range) and
  **Contract due for Renewal Listing**, which reuses SRV-014's 30-day
  pre-expiry window exactly (never a second, disagreeing window).

### SALES-006 — Contract–Quotation link — CONFIRMED and built (2026-09-15)

- Dennis, 2026-09-15: "Contract renewal link to quotation and contract
  expiry option to link/convert to quotation." Built as a **real
  link**, replacing the free-text reference that stood in for it:
  - `contracts.quotation_id` → the Sales Quotation the contract came
    from. Set automatically when a quotation is accepted (both the
    Service Support and the Annual contract it creates point back at
    it), or linked by hand on the contract page to any quotation of
    the same customer. The old free-text `quotation_reference` is kept
    read-only where it was recorded; nothing new is entered into it.
  - **Generate new quotation** on a contract that is **coming due** —
    Dennis, 2026-09-15: "it's allowing to generate a quotation from
    Contract to New Quotation when it's going to due / hrs finishing…
    date going to due". Coming due means any of: within SRV-014's
    30-day pre-expiry window; **hours finishing** (a Service Support
    contract with **20% or less** of its contracted hours left —
    confirmed by Dennis 2026-09-15); already expired; or exceeded. It
    raises a draft quotation for the same customer carrying the
    contract's current terms as its line (contracted hours × blended
    rate for Service Support; the annual value for Annual), marked as
    renewing that contract (`quotations.renews_contract_id`), and the
    contract page shows why it is due. It then goes through SALES-008's
    approval and sending like any other quotation. The manual
    "link an existing quotation" picker was removed from the contract
    page at the same time: the link is what the system records, not a
    thing to do by hand (the endpoint remains for the Renew form).
  - **Accepting a renewal quotation renews the contract** through the
    same `renewContract()` Renew uses -- SRV-010 (new record, own
    allocation) and SRV-016 (2-week backdating window) apply exactly;
    beyond the window the acceptance stands but the contract is not
    renewed, and the message says so, so a human makes the SRV-018
    case-by-case call on the Renew form (which now takes the quotation
    as a picker rather than free text).
  - Ad Hoc Rate contracts have no upfront value to quote, so they get
    no renewal quotation; they are renewed directly, as before.
  - One open renewal quotation per contract at a time: while one is
    draft / pending approval / approved / sent, the button is replaced
    by a link to it.

### SALES-007 — Sales Dashboard KPIs — CONFIRMED, with two pragmatic defaults

- Below the Company Dashboard: Contracts Due for Renewal (reuses
  SALES-005's renewal-due logic), Total/2-month/3-month AR Outstanding
  (reuses the AR Aging report's own bucket logic exactly), and a Top 10
  Sales Billing Customer / Bottom 10 Non-Active Customer listing for
  "this Financial Year", each figure drilling into its underlying rows.
- **Pragmatic default (flagged for Dennis's confirmation, not silently
  assumed):** "this Financial Year" = the **calendar year** (1 Jan – 31
  Dec) — no fiscal-year-start field exists anywhere in the system yet.
- ~~**Known gap, not fabricated:** "Quotations Pending Approval" and
  "Quotations Pending Confirmation by Client" always report
  not-available.~~ **Closed 2026-09-15** by SALES-008 below: the two
  tiles count `pending_approval` and `sent` quotations respectively.

- **Per-salesperson cards** (Dennis, 2026-09-26, decision 12.2 / #50).
  Each salesperson's card shows their prospects by stage, and what they
  quoted, billed and got paid this month, with the financial year to
  date beside it (Dennis, 2026-09-26: "This month, with the year beside
  it"). Work counts on its
  prospect's salesperson; work with no prospect goes on a "No prospect"
  card. The owner, Sales Manager and Sales Supervisor see every card;
  anyone else sees only their own.

### SALES-008 — Quotation status model — CONFIRMED (2026-09-15)

- Dennis, 2026-09-15: "Quotation status to clarify." Settled on the
  rule already confirmed as BILL-006 (the Sales Manager approves every
  quotation before it goes to the customer, no value threshold):

  `draft` → `pending_approval` → `approved` → `sent` → `accepted` /
  `rejected` / `expired`

  - **Submit for approval** (draft → pending_approval): anyone with
    EDIT on Sales.
  - **Approve** (pending_approval → approved) and **Send back**
    (pending_approval → draft, with a reason shown on the draft):
    Sales Manager, or the owner standing in, as on every other
    approval in the system.
  - **Send to customer** (approved → sent): requires approval first.
  - **Accept** (sent → accepted): only a quotation the customer has
    actually been sent can be accepted. What it creates is set out in
    SALES-011.
  - **To revise** (sent → `to_revise`, with what the customer asked to
    change) — Dennis, 2026-09-15: "the status come back is Accepted /
    Rejected / To Revise". Quotation lines are not editable once
    raised, so **Create revision** raises a **new draft quotation**
    copying the lines (and the contract it renews, if any), linked
    back to the original, which then goes through approval and
    sending again. The original stays `to_revise` showing its
    revision; one open revision at a time. A `to_revise` quotation
    cannot be accepted — its revision is what gets accepted — but it
    can be rejected.
  - **Reject**: from any state before acceptance — a customer can
    decline, or Sales can withdraw, at any point up to acceptance.
  - Who submitted / approved / sent, and when, are recorded on the
    quotation as well as in the audit trail.
- The Sales Dashboard's "Quotations Pending Approval" = count of
  `pending_approval`; "Pending Confirmation by Client" = count of
  `sent`. Each tile opens the Quotations list filtered to that status.

### SALES-011 — What accepting a quotation creates — CONFIRMED and built (2026-09-26)

- **Product lines → a Sales Invoice, issued on acceptance** (Dennis,
  2026-09-26, decision 11.2: "Straight away on acceptance"). A product
  line is one whose catalog item is **Product**-type (hardware, a
  licence sold outright); Service-type items and free-text lines are
  not.
  - The invoice is issued the moment the quotation is accepted, through
    the same path as Raise Sales Invoice (GST, numbering, due date, GL
    posting).
  - A product kept in stock takes its stock from the warehouse picked
    on Accept, at weighted average cost. If any warehouse is short, the
    whole acceptance is refused and nothing changes; stock is never
    negative.
  - A product line's quantity must be whole.
  - A non-stock product line carries the quotation line's cost, so its
    gross profit is known.
  - The invoice carries the quotation's prospect, and the quotation
    records the invoice.
- **Hour lines → one Service Support contract; every other line → one
  Annual contract**, as confirmed 2026-09-10 (11.1). A renewal
  quotation renews its contract with those lines, and its product
  lines are invoiced as above.

### SALES-009 — Prospect / Leads — CONFIRMED and built (2026-09-26)

Dennis, 2026-09-26: "It should start with a Prospect/Leads Module
linking to Company/Individual, then each prospect will have their own
prospect activities… logged by the salesman on the go using mobile…
each prospect will then link to multiple Quotations… If Quotation is
confirmed, then Invoice generated will also be tied back to this
Prospect… estimated value, Quoted Amount, Billed Amount, Paid Amount /
Outstanding." And: "There should not be any CRM from here."

- A **Prospect** is one sales opportunity for one Company / Individual
  (a company can have several over time). Numbered `PRS-YYYY-NNNN`
  (format editable under Document Control). Its Company / Individual
  cannot change once raised -- its quotations and invoices belong to it.
- **Prospect Activities** (call, email, meeting, note, follow-up,
  proposal, demo, negotiation) are logged against a prospect, not
  directly against the Company / Individual. The Mobile App's
  Prospects tab lets the salesperson open a prospect and log one on the
  spot; logged on the go, it is stamped with the current time.
- A prospect can carry **several quotations**. A quotation is put
  under a prospect when it is raised (the prospect must be for the same
  Company / Individual), keeps it through revisions, and an earlier
  quotation can be linked afterwards.
- **Invoices are tied back to the prospect**: an invoice raised from a
  contract that one of its quotations became (contract activation, an
  excess-usage decision, or by hand against the contract) records the
  prospect. Linking an earlier quotation brings its already-issued
  invoices with it.
- The prospect reports **Estimated value** (entered by the
  salesperson), **Quoted** (its quotations sent to or accepted by the
  customer), **Billed** (its invoices' totals), **Paid**, and
  **Outstanding** (written-off invoices count as nothing outstanding).
  Quoted, billed, paid and outstanding include GST.
- **Pipeline stages** (confirmed 2026-09-26, "Pipeline stages is
  good"): New → Qualified → Proposal → Negotiation, closing as **Won**
  or **Lost** (Lost needs a reason). The salesperson moves the prospect
  along by hand, except that **the customer accepting one of its
  quotations marks it Won** (confirmed 2026-09-26: "Yes"), from any
  stage, recorded in Event Logs.
- **Activities are never deleted** (confirmed 2026-09-26: "should not
  allow them to delete, only can change status to VOID"). A mistaken
  activity is voided with a reason -- who voided it and when are kept,
  and it is audited -- and stays on the prospect marked VOID; a VOID
  activity can no longer be edited.
- The Main Menu starts with a **Sales** section: Prospect / Leads, then
  Prospect Activities. The module is switched per company and granted
  per group under Module Control / Group Authority as **Prospect /
  Leads** (`prospects`), which replaced the old `crm` key -- every
  company's on/off setting and every group's access level carried over.
- Pragmatic defaults taken where no rule was given -- statuses, who
  sees what, what counts as "quoted" -- are listed in
  [open-business-decisions.md #46](open-business-decisions.md#46-prospect--leads-defaults-taken-raised-and-built-2026-09-26).

### SALES-010 — Sales roles — CONFIRMED and built (2026-09-26)

Dennis, 2026-09-26: "Definitely need sales staff roles… sales
supervisor and manager."

- Roles now: Owner, Service Lead, **Sales Manager**, **Sales
  Supervisor** (new), **Sales Staff** (new), Support Engineer, Finance.
  Set on Staff Master. As before, role drives the named-responsibility
  rules and Group Authority drives module access.
- Sales Staff see and work on their own prospects (the ones they are
  the salesperson on, or raised) and the activities on them; the owner,
  Sales Manager and Sales Supervisor see every prospect and may assign
  one to any salesperson.
- Every sales role (and the owner) gets the Mobile App's Prospects,
  Quotations and Jobs tabs.
- Quotation approval (BILL-006) stays with the Sales Manager and the
  owner; the Sales Supervisor does not approve quotations.

## GST Business Rules (CONFIRMED)

### GST-001 — GST F5 workflow — CONFIRMED and built (2026-09-26)

Dennis, 2026-09-26: "Every month, when the last month transactions all
settled keying in, they will look for the period in Period and GST
Accounting and locked it up. In each period, there is a button to do
GST Calculation, meaning based on the locked data, this GST Calculation
screen will pull out the figures based on those document status and sum
up like form 5 and keep it there… after each month all done, the report
will pull those required information from what is saved inside. GST
supporting reports also… all use the data that is kept inside."

- **GST and Account Period** screen: once a month's period is locked
  (Close All), its **GST Calculation** button sums the month into the
  IRAS **Form 5** boxes 1–13 and keeps them, together with every
  document behind them as it stood. It is refused while the period is
  open. Recalculating adds the next version and keeps the earlier one
  as superseded; both are in Event Logs.
- **Which documents count** ("based on those document status"): every
  Sales Invoice issued in the month (Singapore date) — outstanding,
  paid or written off — except invoices brought in by Data Migration
  (already filed from the old system); and every supplier bill dated in
  the month that reached Accounts Payable (approved, partly paid or
  paid — not one awaiting a match or held as an exception).
- **Boxes**: tax code SR → box 1, ZR → box 2, ES → box 3, OS → revenue
  (box 13) only; output tax → box 6. **Supplier bills carry a purchase
  tax code "like Sales Invoice Logic"** (confirmed 2026-09-26): the bill
  picks TX (standard-rated), ZP (zero-rated), EP (exempt), OP (out of
  scope) or NR (supplier not registered for GST), and its GST is worked
  out from that code's rate — never keyed in. TX and ZP are taxable
  purchases (box 5), TX's GST is box 7; EP / OP / NR are listed but not
  in box 5. Box 8 = 6 − 7. **Boxes 9–12 stay zero** — confirmed
  2026-09-26 as not relevant to Webmaster. Defaults behind this mapping:
  [open-business-decisions.md #47](open-business-decisions.md#47-gst-f5-workflow-defaults-taken-raised-and-built-2026-09-26).
- **The box is set on each tax code** (2026-09-26): Maintenance → Tax
  Types gives every sales code a Form 5 box (1, 2, 3 or out of scope)
  and every purchase code one (5 or not taxable). The built-in codes
  are pre-set as above; a new code is counted where it is set, and a
  code with no box set falls back to the mapping above (an unknown
  sales code with GST counts as box 1). A box that does not fit the
  code's kind is refused.
- **Reports read only what was kept**: Accounting Reports → **GST
  Return (Form 5)** adds up the saved months in the chosen range (a
  quarter is its three months) and names any month not yet calculated
  or reopened since; **GST Supporting Listing** lists the kept
  documents (sales, purchases or both), with CSV / Excel / PDF.
  Nothing is filed with IRAS from here and nothing posts to the ledger.
- **Submitted to IRAS — then locked** (confirmed 2026-09-26: "we add in
  submitted by who… date time stamp… after that must lock"): once the
  return is filed, **Submit to IRAS** on the period records who and
  when (in Event Logs too). From then on the month cannot be
  recalculated, its period cannot be reopened, and no lock in it can be
  lifted.
- **Revised and resubmitted, keeping the old record** (Dennis,
  2026-09-26: "Have to allow resubmission like revision but have to
  keep the old record"): **Revise** on a submitted month, with a reason,
  records who, when and why on the submitted return; its figures,
  documents and submission stay exactly as they were. The month can
  then be unlocked and corrected, locked again and recalculated -- the
  new version says which submitted version it revises -- and submitted
  in its turn, which locks the month again. Every version, submitted or
  not, stays on file and is listed on the period. (IRAS takes a
  correction to a return already filed as a GST F7; this records the
  corrected figures, it does not file anything.) Revise is for whoever
  may submit -- FULL on GST and Account Period (confirmed 2026-09-26).

## Software Tasks

### DEV-001 — Software Task statuses — CONFIRMED and built (2026-09-26)

- **Statuses** (Dennis, 2026-09-26, decision 12.1 / #50): a task goes
  **Open → Programming → For Testing → Tested → Released**. Open may go
  straight to For Testing. A failed test sends a task from For Testing
  back to Programming, and a Tested task can be reopened for testing.
- **Released** means the change has gone out. It can only follow
  Tested, and it is final.
- **Who moves a task:** anyone with EDIT on Software Development, for
  every move including Released. Each move is recorded in Event Logs.
- **Tasks that already existed** were placed by what they showed:
  - tested ones became Tested;
  - untested ones past their finish date became For Testing;
  - the rest became Open.
- **Per-programmer cards** on Software Tasks show each programmer's
  open tasks, overdue ones (past the finish date and not yet tested)
  and ones awaiting test (decision 12.3).
- Support Monitoring's "Un-Tested S/T" still counts every task not yet
  Tested or Released.

## Conceptual Business Entities

This section lists the major business entities Websoft Service ERP Solution is expected
to eventually need, and describes how they relate to one another
conceptually. This is **not** a database schema — there are no tables,
columns, or keys here. It exists to give a shared vocabulary for the
module structure ([module-map.md](module-map.md)) and workflows
([workflows.md](workflows.md)), and to be refined as detailed requirements
are gathered per module.

### Entity list, by area

**Core / people & organizations**
- Company — a legal entity using the system (Webmaster Consultancy Pte Ltd
  today; the model should not preclude more companies later).
- User — a system login/account.
- Employee — a staff member; may or may not be the same record as a User,
  depending on future decisions.
- Role / Permission — defines what a User can do (RBAC).
- Customer — a company or individual Webmaster does business with.
- Contact — a person associated with a Customer (or Supplier).
- Site — a physical location associated with a Customer (for service
  delivery, hardware installation).
- Supplier — a company Webmaster purchases from.

**CRM & Sales**
- Lead — an unqualified prospective opportunity.
- Sales Opportunity — a qualified, tracked potential sale.
- Quotation — a formal price/scope offer to a Customer.
- Quotation Line — one priced item/service/hardware line within a
  Quotation.
- Sales Order — a confirmed commitment from an accepted Quotation.
- Sales Order Line — one line within a Sales Order.

**Service Contracts & Delivery**
- Contract — a recurring service agreement with a Customer, standard
  duration 12 months (SRV-001), with a stored start/expiry date and a
  lifecycle of Draft → Active → Exceeded (if applicable) → Expired /
  Renewed.
- Contract Line — a specific service/entitlement (e.g. included hours,
  minimum 10 per SRV-002) within a Contract.
- Contract Hour Consumption Record — a record of contracted hours used,
  forming the contract's usable balance, which can never go negative
  (SRV-004).
- Excess Usage Record — a record of support usage beyond contracted
  hours (SRV-003), recorded separately from normal consumption, pending
  or carrying Nico's treatment decision and reason (SRV-004).
- Expired Hours Record — the record of unused contracted hours forfeited
  at contract expiry (SRV-005), retained for reporting/audit.
- Job Order — a logged customer issue or service request.
- Project — a scoped body of work delivered to a Customer.
- Project Task — a unit of work within a Project.
- Service Record — a record of time an Employee spent against a
  Job Order, Project Task, or Contract.

**Commerce & Finance**
- Product / Service (item master) — something Webmaster sells (a
  service, a hardware product, etc.).
- Invoice — a bill issued to a Customer.
- Invoice Line — one billed item within an Invoice.
- Credit Note — a correction/reduction against an Invoice.
- Payment (Customer) — money received from a Customer.
- Purchase Order — a confirmed order to a Supplier.
- Purchase Order Line — one line within a Purchase Order.
- Goods Receipt — a record of goods physically received against a
  Purchase Order.
- Supplier Invoice — a bill received from a Supplier.
- Payment (Supplier) — money paid to a Supplier.
- Journal Entry / General Ledger Account — the financial posting layer
  that records the accounting impact of the above.
- Commission (record) — a calculated, approved, and eventually paid
  commission amount tied to a sale and a salesperson.

**Inventory & Hardware**
- Inventory Item — a stocked item, tracked by quantity at a location.
- Stock Movement — a recorded change in inventory (receipt, issue,
  transfer, adjustment).
- Hardware Asset — an individually serial-tracked unit of hardware, from
  receipt through installation and service life.
- Warehouse / Location — a place where stock or assets are held.

**Cross-cutting**
- Audit Log Entry — a record of a significant action taken on another
  entity (who, when, what changed), supporting the audit-trail
  requirement in [CLAUDE.md](../CLAUDE.md).

### Relationships, conceptually

- A **Company** is the top-level context for nearly everything else
  (Users, Customers, Contracts, Invoices, etc.) — today there is one
  Company, but the model should anticipate more than one in the future.
- A **User** may correspond to an **Employee**; a User has one or more
  **Roles**, which grant **Permissions**.
- A **Customer** has one or more **Contacts** and one or more **Sites**.
  A Customer is "owned" by a salesperson/account owner (rule to be
  decided — see [open-business-decisions.md](open-business-decisions.md)).
- A **Lead**, once qualified, becomes a **Sales Opportunity**, which is
  linked to a Customer (or a not-yet-a-customer prospect) and to a
  Contact.
- A **Sales Opportunity** may lead to one or more **Quotations**, each
  made up of **Quotation Lines** that reference a **Product/Service**.
- An accepted **Quotation** becomes a **Sales Order**, with **Sales Order
  Lines** mirroring the quotation lines (possibly adjusted).
- A **Sales Order** may result in one or more of: a **Contract**, a
  **Project**, and/or **Hardware Assets** being allocated for delivery —
  depending on what was sold.
- A **Contract** has one or more **Contract Lines**, each defining an
  entitlement (e.g. included hours of a given service). A Contract
  belongs to one Customer, and its lifecycle status governs whether
  further consumption is checked, flagged as Exceeded, or blocked because
  it has Expired or been Renewed.
- A **Job Order** belongs to a Customer, optionally references a
  **Hardware Asset**, and is checked against the Customer's active
  **Contract** for entitlement.
- A **Project** belongs to a Customer (and optionally a Sales Order), and
  is broken into **Project Tasks**.
- A **Service Record** belongs to an Employee and references exactly one
  of: a Job Order, a Project Task, or (indirectly, via either of
  those) a Contract. An approved entry against a Contract is validated
  against the Contract's remaining balance: if hours remain, it reduces
  the **Contract Hour Consumption Record**; if the contract is already at
  or beyond its entitlement (per SRV-003), it instead creates an
  **Excess Usage Record** for Nico's review rather than reducing the
  balance below zero (SRV-004). At contract expiry, any remaining balance
  becomes an **Expired Hours Record** (SRV-005) rather than being
  consumable further.
- An **Invoice** belongs to a Customer and has one or more **Invoice
  Lines**, each of which may originate from a Sales Order Line, a
  Contract Line (recurring billing), a Service Record (billable time),
  or a Hardware Asset (hardware sale). A **Credit Note** references an
  Invoice it corrects.
- A **Payment (Customer)** is allocated against one or more Invoices
  (allocation rule to be decided).
- A **Purchase Order** belongs to a Supplier and has one or more
  **Purchase Order Lines**, each referencing a Product/Service or
  Inventory Item. A **Goods Receipt** references a Purchase Order (in
  full or in part) and results in **Stock Movement** records and,
  for serialized items, new **Hardware Asset** records.
- A **Supplier Invoice** references a Purchase Order and Goods Receipt(s)
  it is matched against, and a **Payment (Supplier)** is made against it.
- A **Hardware Asset** originates from a Goods Receipt, is optionally
  allocated to a Sales Order, and is optionally linked to a Customer, a
  Site, a Contract (for coverage), and Job Orders raised against it.
- A **Commission** record references a Sales Order (and/or the Invoice/
  Payment that triggers it, per the decision still to be made) and the
  salesperson(s) it is paid to.
- **Journal Entries** are created from Invoices, Payments (customer and
  supplier), Supplier Invoices, and Commission records, to keep the
  General Ledger consistent with operational activity.
- **Audit Log Entries** reference the entity and action they record, and
  are expected to attach to most of the entities above wherever the
  "financial and operational transactions must have audit trails" rule
  in [CLAUDE.md](../CLAUDE.md) applies.

This entity list and its relationships will be refined — and formal data
models/tables designed — once the business decisions in
[open-business-decisions.md](open-business-decisions.md) are resolved and
detailed, per-module requirements are gathered.
