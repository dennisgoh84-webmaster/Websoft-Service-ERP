# Business Decisions Required From Webmaster

This document lists business rules and decisions that **cannot be safely
assumed** and must be decided by Dennis (or whoever Webmaster Consultancy
designates) before the affected areas can be designed in detail.

Per the CLAUDE.md development rule "Never assume a business rule when
requirements have not been provided," none of these have been decided on
Webmaster's behalf. Where [workflows.md](workflows.md) or
[module-map.md](module-map.md) describe a proposed process, any point that
depends on one of these decisions is described in general/optional terms
and flagged back to this document.

Each item below notes where it arises, so a decision can be traced to its
impact. This list is expected to grow as detailed requirements gathering
continues per module — it is not necessarily exhaustive yet.

Status legend: **OPEN** (undecided), **DECIDED** (resolved — see the
pointer for where the rule is documented), or **PARTIALLY DECIDED** (the
overall approach is decided but a sub-detail remains open — the open
sub-detail is called out explicitly).

---

## 1. Service Contracts & Hours

1.1. **How do service contracts calculate consumed hours?**
   **Status: DECIDED — SRV-007.** Each service record is rounded up to
   the nearest 15 minutes before deduction from the contract balance. See
   [business-requirements.md](business-requirements.md#service-operations-business-rules-confirmed).
   *Arises in:* Service Contracts, Service Records; Workflow C.

1.2. **Do unused contract hours expire, roll over, or get forfeited** at
   the end of a contract period, and if they roll over, is there a cap?
   **Status: DECIDED — SRV-005.** Unused hours expire completely at the
   end of the 12-month contract period: no automatic carry-forward, no
   carry-forward on renewal, no conversion to credit, no transfer to
   another contract. See
   [business-requirements.md](business-requirements.md#service-operations-business-rules-confirmed).
   *Arises in:* Service Contracts; Workflow I.

1.3. **What happens when contract hours are exhausted mid-contract?**
   **Status: DECIDED — SRV-003 and SRV-004.** There is no grace period;
   the contract balance is never reduced below zero; the next unit of
   usage beyond entitlement becomes Excess Usage requiring Nico's
   (Service & Support) review and decision, recorded and auditable. The
   system does not auto-continue deducting and does not auto-bill without
   that review. See
   [business-requirements.md](business-requirements.md#service-operations-business-rules-confirmed).
   *Arises in:* Service Contracts, Helpdesk / Service Operations, Billing;
   Workflow C.

1.4. **How is support/work beyond contract entitlement billed?**
   **Status: DECIDED — SRV-008.** Billable excess usage is charged at the
   contract's own blended rate (contract value ÷ contracted hours); no
   customer pre-approval is required before invoicing — the customer is
   billed then notified.
   *Arises in:* Billing, Helpdesk / Service Operations; Workflow C.

1.5. **What SLA terms apply, and how are SLA breaches handled?**
   **Status: DECIDED (deferred) — SRV-009.** No formal SLA response/
   resolution targets are defined at this time; this is an explicit
   decision to defer, not an open gap. Job Order priority and timestamps
   are still tracked so targets can be added later without a data-model
   change.
   *Arises in:* Service Contracts, Helpdesk / Service Operations;
   Workflow C.

1.6. **Does contract renewal create a new contract record or extend the
   existing one**, and how is a coverage gap (if renewal is late) handled?
   **Status: DECIDED — SRV-010/SRV-016.** Renewal creates a new Contract
   record (referencing the prior one for history), backdated to
   immediately follow the prior contract's expiry so there is no coverage
   gap — as long as renewal happens within **2 weeks** of expiry
   (SRV-016; see item 1.12). Beyond that window it is not backdated (see
   item 1.13 for what happens then).
   *Arises in:* Service Contracts; Workflow I.

1.7. **Is a customer credit check or credit limit required before
   activating a new contract?**
   **Status: DECIDED — SRV-017.** Not required for now; contracts
   activate based on the commercial/sales agreement alone.
   *Arises in:* Service Contracts, Customer Management; Workflow B.

1.8. **Who is the backup/delegate reviewer for excess usage when Nico is
   unavailable?**
   **Status: DECIDED — SRV-011.** Cherish (Sales Manager) is the confirmed
   backup reviewer; the same recording/auditability requirements apply
   to her decisions as to Nico's.
   *Arises in:* Service Contracts, Helpdesk / Service Operations;
   Workflow C.

1.9. **What is the authorized override mechanism for a service contract
   below the SRV-002 minimum of 10 hours?**
   **Status: DECIDED — SRV-012.** There is no override mechanism; 10
   hours is a hard minimum with no exceptions until Dennis decides
   otherwise.
   *Arises in:* Service Contracts, Sales; Workflow B.

1.10. **What other treatments (beyond billable excess / approved
   non-billable excess) may apply to excess usage under SRV-004/SRV-006,
   and what rules govern each?**
   **Status: DECIDED — SRV-013.** Two further categories are confirmed:
   Warranty / Goodwill, and Internal Write-off. Both require the same
   recorded, auditable reason as any other treatment under SRV-004.
   *Arises in:* Service Contracts, Billing; Workflow C.

1.11. **What lead time before contract expiry should the SRV-006
   pre-expiry accounting check (open job orders, missing service records,
   unapproved/unbilled excess) begin?**
   **Status: DECIDED — SRV-014.** The check begins 30 days before a
   contract's expiry date.
   *Arises in:* Service Contracts, Reporting; Workflow I.

1.12. **What is the maximum window after expiry within which a renewal
   still qualifies for seamless (backdated, no-gap) coverage under
   SRV-010?**
   **Status: DECIDED — SRV-016.** The maximum window is **2 weeks**. A
   renewal within 2 weeks of expiry is backdated to avoid a coverage gap;
   beyond 2 weeks, it's treated as a fresh, non-contiguous contract (see
   item 1.13 for what happens in that later case).
   *Arises in:* Service Contracts; Workflow I.

1.13. **How is a renewal handled if it happens more than 2 weeks after
   expiry** (per SRV-016)?
   **Status: DECIDED — SRV-018.** No fixed rule; handled case-by-case by
   Nico, Cherish, or Dennis.
   *Arises in:* Service Contracts, Helpdesk / Service Operations;
   Workflow I.

## 2. Billing & Invoicing

2.0. **Is the company GST-registered, and how is GST applied?**
   **Status: DECIDED (2026-09-10).** Webmaster Consultancy is
   GST-registered and its services are **standard-rated** (tax code SR,
   currently 9%). Invoices are tax invoices showing the supplier's name,
   address and GST registration number, a serial invoice number, and the
   net / GST / total split. The rate is held in a tax code table rather
   than hard-coded, so a rate change is a configuration change, and each
   invoice stores the rate it was raised at. Zero-rated (ZR), exempt (ES)
   and out-of-scope (OS) codes exist for future use but nothing is
   assumed to use them yet.
   *Arises in:* Billing, Accounts Receivable, Finance / Accounting.

2.1. **What is the recurring billing cycle for contracts?**
   **Status: DECIDED — BILL-001.** Annual upfront — the full 12-month
   contract value is billed at contract start/renewal.
   *Arises in:* Billing, Service Contracts.

2.2. **What invoice approval rules apply** before an invoice is issued to
   a customer?
   **Status: DECIDED — BILL-002.** No approval required; invoices issue
   directly.
   *Arises in:* Billing.

2.3. **What is the credit note approval process** (who can approve, at
   what value)?
   **Status: PARTIALLY DECIDED — BILL-003.** Finance or Cherish approves
   routine credit notes; above a value threshold, Dennis approves. **Still
   OPEN:** the exact threshold (see item 2.7).
   *Arises in:* Billing.

2.4. **What billing method applies to projects**?
   **Status: DECIDED — BILL-004.** Fixed price / milestone billing.
   *Arises in:* Billing, Projects; Workflow D.

2.5. **When is revenue recognized** for contracts, projects, and hardware
   sales?
   **Status: DECIDED — BILL-005.** On invoice, for all three.
   *Arises in:* Billing, Finance / Accounting; Workflows B, D, G.

2.6. **How are quotations internally approved** before being sent to a
   customer?
   **Status: DECIDED — BILL-006.** Cherish (Sales Manager) approves every
   quotation — no threshold exemption.
   *Arises in:* Sales; Workflow A.

2.7. **What is the value threshold above which a credit note requires
   Dennis's approval** (per BILL-003)?
   **Status: STILL OPEN**, but not blocking: like the write-off
   threshold, this is a configurable field in Company Setup, unset by
   default, and while unset the owner approves.
   *Arises in:* Billing.

## 3. Payments & Accounts Receivable

3.0. **What payment terms apply to customer invoices?**
   **Status: DECIDED (2026-09-10).** Terms **vary per customer** — there
   is no company-wide default. `payment_terms_days` is set on each
   customer record and drives the invoice due date and AR aging. A
   customer with no agreed terms gets invoices with **no due date**
   rather than an invented one, and those invoices age as "current"
   until terms are agreed.
   *Arises in:* Accounts Receivable, Billing, Customer Management.

3.1. **How are customer payments allocated** when a payment does not
   exactly match one invoice, or covers multiple invoices?
   **Status: DECIDED — AR-001.** Finance specifies the allocation
   manually, based on remittance information — no automatic rule.
   *Arises in:* Accounts Receivable; Workflow H.

3.2. **What is the write-off / bad debt process**, and who approves it?
   **Status: PARTIALLY DECIDED — AR-002.** Finance can write off small
   amounts directly; above a threshold, Dennis approves. **Still OPEN:**
   the exact threshold (see item 3.4).
   *Arises in:* Accounts Receivable; Workflow H.

3.3. **What happens to an invoice under dispute?**
   **Status: DECIDED — AR-003.** It continues through normal
   collections/aging; no automatic hold.
   *Arises in:* Accounts Receivable, Billing; Workflow H.

3.4. **What is the value threshold above which a write-off requires
   Dennis's approval** (per AR-002)?
   **Status: STILL OPEN**, but no longer blocking: the threshold is a
   configurable field in Company Setup rather than a hard-coded number.
   Until Dennis sets one, the system requires the **owner's approval for
   every write-off** — the safe reading of an undecided rule.
   *Arises in:* Accounts Receivable.

## 4. Purchasing & Accounts Payable

4.1. **What purchase order approval thresholds apply?**
   **Status: PARTIALLY DECIDED — PUR-001.** Value-based: below a
   threshold, procurement/finance approve directly; above it, Dennis
   approves. **Still OPEN:** the exact threshold (see item 4.4).
   *Arises in:* Purchasing; Workflow F.

4.2. **Is 2-way or 3-way matching required** for supplier invoices?
   **Status: DECIDED — PUR-002.** 2-way matching (PO + invoice only); no
   separate goods-receipt match required.
   *Arises in:* Accounts Payable, Purchasing; Workflow F.

4.3. **What is the supplier invoice approval process** before payment is
   released?
   **Status: DECIDED — PUR-003.** Auto-approved once the invoice matches
   the PO (per PUR-002); a mismatch is an exception (handling not yet
   decided — see item 4.5).
   *Arises in:* Accounts Payable; Workflow F.

4.4. **What is the value threshold above which a purchase order requires
   Dennis's approval** (per PUR-001)?
   **Status: STILL OPEN**, but not blocking: a configurable field in
   Company Setup, unset by default, owner approves while unset.
   *Arises in:* Purchasing.

4.5. **How are PO/invoice matching mismatches handled** under the PUR-002
   2-way match (e.g. price or quantity discrepancy)?
   **Status: STILL OPEN, not blocking.** A mismatch (different supplier,
   amount, or an unapproved PO) is recorded as an **exception** with the
   specific discrepancy spelled out, and a bill in that state cannot be
   paid. What happens next — who resolves it, whether it needs a revised
   PO or a credit note — is not decided, so nothing beyond flagging it is
   automated.
   *Arises in:* Accounts Payable, Purchasing; Workflow F.

## 4b. Accounting & Finance (raised 2026-09-10 while building AR)

4b.1. **What is Webmaster's financial year end?** Needed before any
   period close, financial statements, or year-based reporting.
   **Status: mechanism built, date still open.** Accounting Periods
   (`app/models/periods.py`) are plain date ranges an owner/Finance
   defines per company, with no calendar-year assumption baked into the
   backend — so whatever FY end Dennis eventually confirms just becomes
   a period row, not a code change. Confirmed 2026-09-11: until that's
   decided, a date with no period defined at all is unrestricted
   (periods are opt-in protection, not a retroactive block).
   *Arises in:* Finance / Accounting, Reporting.

4b.2. **Which account does each transaction post to?** The chart of
   accounts exists, and the General Ledger + Journal Voucher (JV) are
   now built — but which account a *sales invoice*, a *receipt*, or a
   *bad-debt write-off* posts to automatically is **still not decided**,
   so nothing posts on its own yet. Today the JV is manual: whoever
   raises it picks the accounts. Automatic posting from AR/AP/Billing
   is future work once this is confirmed.
   *Arises in:* Finance / Accounting, Billing, Accounts Receivable.

4b.3. **Is annual-upfront contract revenue deferred and released monthly,
   or taken entirely on invoice?** BILL-005 says revenue is recognized on
   invoice, which suggests the latter, but a 12-month contract billed
   upfront is the classic deferred-revenue case and the two readings give
   very different monthly figures. A "Deferred revenue" account has been
   seeded but is unused pending this decision.
   *Arises in:* Finance / Accounting, Billing.

4b.4. **How are GST returns (F5) prepared and filed**, and over what
   accounting periods? Output tax is captured per invoice, but the return
   itself is not built.
   **Status: partially addressed 2026-09-11.** Accounting Reports →
   Analysis → GST Return now totals output tax (sales, by tax code) vs
   input tax (purchases) for a chosen date range, tax point = invoice
   date. It is read-only: it does not file anything with IRAS, does not
   post to the GL, and does not attempt bad-debt relief on written-off
   invoices (a separate IRAS scheme). The actual filing workflow is
   still open.
   *Arises in:* Finance / Accounting, Integrations.

4b.7. **Year-End Closing mechanics** (raised implicitly by 4b.1;
   confirmed 2026-09-11 in response to an explicit scope question, since
   "what does closing a year actually do" is exactly the kind of thing
   never to assume): Year-End Closing posts one balanced journal entry
   moving every Revenue/Expense account's *movement for the fiscal
   year* (not its all-time balance) into an Equity account the owner
   picks at the time — there is no hardcoded "Retained Earnings"
   account name; the Chart of Accounts' `3100 Retained earnings` is
   simply the obvious seeded choice. Owner-only. Requires every
   Accounting Period tagged with that fiscal year to already be closed.
   Reversible the same way any posted voucher is corrected (General
   Ledger → Reverse) — there is deliberately no separate "unclose"
   mechanism. See `app/services/periods.py` close_fiscal_year.
   *Arises in:* Finance / Accounting.

4b.5. **Does Webmaster ever invoice in a currency other than SGD?**
   Everything is SGD today; multi-currency has not been requested and is
   not assumed.
   *Arises in:* Billing, Finance / Accounting.

4b.6. **What is the invoice number format?** IRAS requires serial
   numbering but no particular layout. The system currently uses
   `INV-<year>-<0001>`, chosen as a convention rather than a decision —
   easily changed.
   *Arises in:* Billing.

## 5. Inventory & Hardware

5.1. **What stock adjustment rules apply?**
   **Status: DECIDED — INV-001.** Requires manager approval before
   taking effect.
   *Arises in:* Inventory.

5.2. **What inventory valuation method is used?**
   **Status: DECIDED — INV-002.** Weighted average cost.
   *Arises in:* Inventory, Finance / Accounting; Workflow G.

5.3. **Does hardware installation require customer sign-off/acceptance**
   before it is considered complete (and billable)?
   **Status: DECIDED — HW-001.** Yes, customer sign-off is required;
   internal confirmation alone is not sufficient.
   *Arises in:* Hardware Management; Workflow G.

5.4. **What is the RMA / hardware failure and replacement process**?
   *Arises in:* Hardware Management, Inventory; Workflow G.

5.5. **What warranty terms apply to hardware**, and how are they tracked
   and enforced?
   *Arises in:* Hardware Management; Workflow G.

## 6. Commission Management

Status: **DECIDED / BUILT** (2026-09-12) — all five items resolved.

6.1. **How are commissions calculated?**
   **DECIDED (2026-09-12):** Flat percentage of Gross Profit, configured
   per company via Commission Settings (Company Setup page). Rate is
   applied to each salesperson's allocated share of received payments.
   *Built:* Commission report under Accounting Reports; commission_settings
   table with rate_percent field.

6.2. **When does commission become payable** — on sales order
   confirmation, on invoicing, or on customer payment received?
   **DECIDED (2026-09-12):** On customer payment received — commission
   is prorated by receipt allocation against invoices.
   *Built:* commission_rows() in reports service calculates from
   receipt → invoice → quotation → salesperson chain.

6.3. **Who approves commission calculations**, and is multi-level
   approval required above certain amounts?
   **DECIDED (2026-09-12):** Single-level approval workflow:
   DRAFT → PENDING_APPROVAL → APPROVED → PAID. Generating payouts
   creates DRAFT records; "Submit" moves to PENDING_APPROVAL; an
   authorized user (accounting_reports FULL) approves. Bulk submit-all
   and approve-all actions available for a given month. Rejection sends
   back to DRAFT with optional reason. Can later be wired into the
   eApproval framework for multi-level authority-based approval.
   *Built:* commission_payouts table, CommissionPayoutStatus enum,
   submit/approve/reject/cancel endpoints, CommissionPayoutsPage.tsx.

6.4. **What is the clawback rule** if a sale is later cancelled or
   refunded after commission was calculated or paid?
   **DECIDED (2026-09-12):** Automatic clawback on invoice write-off.
   When an invoice is written off, a negative-amount commission payout
   (payout_type = CLAWBACK) is auto-created for the salesperson,
   linked to the written-off invoice with clawback_reason. Clawback
   records are auto-approved (no approval needed for negative amounts).
   *Built:* create_clawback() in commissions service, called from
   write_off_invoice endpoint in accounts_receivable router.

6.5. **How is commission paid out** — via payroll, via a
   finance-administered payout, or another mechanism?
   **DECIDED (2026-09-12):** Finance-administered payout within the ERP.
   After approval, an authorized user marks each payout as "Paid" with
   a payment date and optional reference number. No payroll integration
   needed — the ERP tracks the payout status and payment details.
   *Built:* mark_paid endpoint, paid_date/paid_reference/paid_by_user_id
   columns, inline payment form in CommissionPayoutsPage.tsx.

## 7. Projects

7.1. **What is the project budget overrun process** — does a project need
   re-approval if it exceeds budget, and who approves?
   **Status: DECIDED (2026-09-12).** The system alerts when a PROJECT-type
   Job Order's cumulative Service Record hours OR costs exceed the linked
   contract's values. Sales Manager (Cherish) must approve to continue
   past the overrun — a `budget_overrun_approved` flag on the Job Order,
   settable only by `sales_manager` or `owner` role. Until approved, a
   warning banner is shown on the Job Order detail page and new Service
   Records can still be logged (work doesn't stop) but the overrun is
   visibly flagged.
   *Arises in:* Projects; Workflow D.

7.2. **What labour cost rate is used for project cost tracking** (e.g.
   standard cost per role, actual salary-based cost)?
   **Status: DEFERRED (2026-09-12).** No labour costing for now — Dennis
   confirmed this is not needed at this stage. Revisit when cost-centre
   reporting or project profitability analysis is scoped.
   *Arises in:* Projects, Service Records, Finance / Accounting; Workflow D.

7.3. **Who approves milestone completion** for milestone-based billing?
   **Status: DECIDED (2026-09-12).** Sales Manager (Cherish) approves
   milestone completion. Setting a milestone to COMPLETED status requires
   `sales_manager` or `owner` role — other staff can set milestones to
   IN_PROGRESS or SKIPPED but not COMPLETED. Enforced in the milestone
   update endpoint.
   *Arises in:* Projects, Billing; Workflow D.

## 8. Ownership & Permissions

8.1. **How is customer ownership defined and enforced?**
   e.g. can only the assigned account owner edit/view a customer record,
   or is it open to a wider team?
   **Status: DECIDED (2026-09-12).** Open to team — any user with
   appropriate Group Authority access level can view/edit customer
   records. There is no per-record ownership restriction; the existing
   Group Authority module-level access (None/View/Edit/Full) is the
   access control mechanism.
   *Arises in:* Customer Management.

8.2. **How is sales ownership defined?**
   e.g. what happens to ownership when an opportunity or account is
   reassigned, and how are ownership disputes (two reps claiming a deal)
   resolved?
   **Status: DECIDED (2026-09-12).** Open to team — Group Authority
   controls access to sales documents (Quotations, Invoices). There is
   no per-record sales ownership restriction. Commission is tracked per
   salesperson via the Commission Management module (section 6) but does
   not gate document access.
   *Arises in:* CRM, Sales, Commission Management.

8.3. **How is service ownership defined** for ongoing contracts/job orders
   (e.g. a named account engineer vs. a shared team queue)?
   **Status: DECIDED (2026-09-12).** Open to team — any user with Group
   Authority `service_operations` access can view/work on any Job Order
   or Service Record. The `assigned_to_user_id` on a Job Order is
   informational (who's handling it), not an access gate. Shared team
   queue model, not named-account-engineer lock.
   *Arises in:* Service Contracts, Helpdesk / Service Operations.

8.4. **What are the detailed user roles and permission levels** across the
   system (beyond "authentication and RBAC are required")? e.g. what can a
   support engineer see/edit vs. a finance user vs. a manager.
   **Status: DECIDED (2026-09-10)** — "Group Authority", confirmed with
   Dennis:
   - Every staff member (Staff Master) belongs to **exactly one Group
     per company they work in** (refined 2026-09-10 when multi-company
     went in: Groups are company-scoped, so the assignment lives on the
     staff member's company-access row and the same person can hold a
     different Group in each entity).
   - Each Group has an access level per module: **None / View / Edit /
     Full**. None of the module is hidden; View is read-only; Edit allows
     create/update within the module; Full additionally allows its
     sensitive lifecycle actions (e.g. activating/renewing a contract,
     approving a service record, deciding excess usage, toggling module
     licensing).
   - This is deliberately a **separate axis** from the specific
     named-responsibility rules already confirmed elsewhere (e.g.
     SRV-004/SRV-011: Nico, or Cherish as backup, decides excess usage;
     Dennis as owner). Those rules stay keyed off the small fixed `role`
     field on a user (owner/service_lead/sales_manager/support_engineer/
     finance) and are enforced in addition to, not instead of, Group
     Authority — a user needs both the Group's access level AND (where a
     rule names a role) the matching role to perform that specific action.
   - The owner role always has Full access to every module regardless of
     group, so the owner can never be locked out by a misconfigured Group.
   - Default Groups seeded for the demo: Owner / Admin, Service Team,
     Sales Team, Finance Team — see `backend/scripts/seed_demo.py` for the
     starting matrix; Dennis can create/edit Groups and their matrix from
     the Group Authority admin page, and assign staff to Groups from
     Staff Master.
   *Arises in:* Core / Administration — this affects every module.

## 9. Service Records & Approval

9.1. **Who approves submitted service records**, and within what time frame
   (e.g. weekly approval by a direct manager)?
   **Status: DEFERRED** (2026-09-10, at Dennis's request) — not being
   decided for now. The current build uses a pragmatic default (any user
   with role service_lead, sales_manager, or owner can approve) purely so
   the application functions end-to-end; this is not a business decision
   and should be revisited when this area is finalized.
   *Arises in:* Service Records; Workflows C, D.

9.2. **How is time classified as billable, non-billable, or
   contract-covered**, and can staff choose, or is it determined by the
   job order/project/contract context automatically? **Status: PARTIALLY
   DECIDED** — SRV-003/SRV-004 confirm that the contract balance itself
   determines whether logged time is contract-covered or Excess Usage
   requiring Nico's review; still open is how billable vs. non-billable
   is classified once work is not tied to a contract at all (e.g. pure
   project time).
   *Arises in:* Service Records, Helpdesk / Service Operations, Projects,
   Service Contracts; Workflow C.

9.3. **What is the expected timeframe for submitting service records**, such
   that a "missing service record" can be flagged?
   **Status: DECIDED — SRV-015.** Service Records must be submitted within 3
   business days of the work being performed; a service record not submitted
   within that window is flagged as missing, feeding the SRV-014
   pre-expiry check and the Service Operations dashboard.
   *Arises in:* Service Records, Service Contracts; Workflow C, Workflow I.

## 10. Data & Scope (carried over from business requirements)

Status: **DEFERRED** (2026-09-10, at Dennis's request) — Odoo migration
planning is parked for now; revisit once Service Operations (and related
areas) are finalized.

10.1. **Which historical Odoo data is "important" and must be migrated
   as fully operational**, versus which can be archived in a read-only/
   reference form?
   *Arises in:* Integrations, all modules with historical data; see also
   [business-requirements.md](business-requirements.md).

10.2. **What is the phasing order for module-by-module Odoo replacement**
   (which module goes first, and what defines "ready to cut over" for
   each)?
   *Arises in:* Integrations, all modules.

## 11. Sales (Quotations & Product Catalog, raised 2026-09-10)

11.1. **How should a Sales Quotation's mixed-unit line items map onto a
   Service Contract's required hours**, when the quotation is accepted?
   **Status: DECIDED (2026-09-10).** There are two kinds of Contract
   (`ContractKind`): SERVICE_SUPPORT (hours-based, SRV-002/012's
   10-hour minimum applies) and ANNUAL (a term-only contract, e.g. an
   annual software warranty/maintenance contract -- a value and a
   12-month duration, no hours at all). A quotation's lines split by
   unit of measure: "Hours"/"Hour" lines become one SERVICE_SUPPORT
   contract (summed hours + their value); every other line becomes one
   ANNUAL contract (summed value). A mixed quotation converts to BOTH,
   never blending the two. Job Orders/Service Records can be logged
   against an ANNUAL contract same as any other -- there is just
   nothing to deduct or exceed (`ServiceRecordOutcome.NOT_HOUR_METERED`).
   *Arises in:* Sales, Service Contracts, Billing.
   *Where implemented:* `app/models/contracts.py` (ContractKind),
   `app/models/quotations.py`, `app/services/quotations.py`,
   `app/services/service_records.py`.

11.2. **Should an accepted quotation ever auto-create an Invoice**
   directly (as an alternative or in addition to the Contract
   conversion above), e.g. for one-off product/hardware lines that
   aren't a service contract at all?
   *Arises in:* Sales, Billing, Hardware Management (deferred, section
   5).

## 12. Support/Sales/Dev Monitoring Dashboards & Software Task (raised 2026-09-10)

Reference: a legacy "Monitoring Support" screen (screenshot shared
2026-09-10) showing per-support-staff workload (Job Orders, service
records, contract hours) plus several features/terms not yet built.

12.1. **Software Task** -- confirmed 2026-09-10: "a Software Task that
   is assigned to Support Staff for Testing but not yet tested by the
   staff" (the legacy screen's "Un-Test S/T"). This is a distinct
   entity from Job Order/Service Record -- an assignable task with its
   own testing/verification workflow -- used by both the Support
   monitoring dashboard and the future Software Development dashboard.
   **Status: not yet modeled or built.** Needs its own scoping
   (fields, who creates it, what "tested" means, states beyond
   tested/untested) before building -- deliberately left out of the
   first Support Monitoring dashboard (`app/services/monitoring.py`)
   rather than guessed.
   *Arises in:* Support Monitoring, Software Development (new area,
   not in the original module map).

12.2. **Sales Department monitoring dashboard** and **12.3. Software
   Development monitoring dashboard** -- both mentioned 2026-09-10 as
   needed alongside Support Monitoring, no requirements gathered yet
   (what they should show, and for Dev, how Software Task fits in).
   *Arises in:* Sales, Software Development (new area).

12.4. **Several legacy-screen elements were intentionally left out of
   the first Support Monitoring build**, not yet understood well
   enough to implement correctly: "Support Tool", "Job Schedule",
   "Incident Enquiry", "Phone Call Back" (a customer callback queue),
   and "Projects / OD" (Projects is itself a deferred module -- section
   7). Revisit if/when Dennis wants any of these.

## 13. Contract Type, Product Coverage, Sales Staff (raised 2026-09-11)

13.1. **Third Contract Type -- Ad Hoc Rate.** **Status: DECIDED
   2026-09-11.** `ContractKind` now has three values, each with its own
   offset method: SERVICE_SUPPORT deducts hours from a pool, ANNUAL is
   time coverage only (a term and a value, no hours), and AD_HOC has
   neither -- it stores only a reference hourly rate (no upfront
   value, `contract_value_sgd` forced to 0). Confirmed: nothing is
   auto-deducted or auto-invoiced off an Ad Hoc contract's rate --
   Job Orders/Service Records can still be logged against it for
   history (same "logged but not deducted" pattern already used for
   ANNUAL), and billing off the reference rate is entirely manual.
   *Where implemented:* `app/models/contracts.py` (`ContractKind.AD_HOC`,
   `Contract.hourly_rate_sgd`), `app/services/contracts.py`
   (`create_contract`), `app/services/service_records.py`.
   *Arises in:* Service Contracts, Billing.

13.2. **Product Coverage and Sales Staff on a Contract.** **Status:
   DECIDED 2026-09-11.** A Contract can be linked to zero or more
   catalog Products (`ContractProduct`, many-to-many) and optionally
   to one Sales Staff user (`Contract.sales_staff_id`, any user, not
   restricted to the sales_manager role). Both are editable after
   creation via `PATCH /api/contracts/{id}`, audited like any other
   contract change. Renewal carries both forward from the prior
   contract by default.
   *Where implemented:* `app/models/contracts.py` (`ContractProduct`),
   `app/routers/contracts.py` (`update_contract`).
   *Arises in:* Service Contracts, Sales, Commission Management
   (deferred -- a Sales Staff field on Contract is a likely input to a
   future commission calculation, but no commission rule has been
   confirmed yet).

13.3. **Coverage-date filtering -- judgment call, not explicitly
   confirmed.** "Coverage date" on the main Contracts screen was built
   as an overlap filter against the contract's existing
   `start_date`/`end_date` (same semantics as the Operations Reports
   Contracts report), rather than a new field. Flagging in case a
   different meaning was intended (e.g. filtering by original contract
   *start* date only).
   *Arises in:* Service Contracts.

13.4. **Contract serial/document number.** **Status: DECIDED 2026-09-11**
   (superseded the "not built" note below -- see 14.2: Dennis confirmed
   "all main documents need to have a system generated running
   number," which covers this).
   *Arises in:* Service Contracts, Document Control.

## 14. Calendar-period filtering and universal document numbering (raised 2026-09-11)

14.1. **"Period from / Period to" filter convention.** **Status:
   DECIDED 2026-09-11.** Every FROM/TO date-RANGE filter in the app
   (Contracts' Coverage, Operations Reports, Accounting Reports'
   From/To, Event Logs) now uses a native month picker (`<input
   type="month">`, e.g. "2026-08") instead of an exact-day date
   picker -- "Period from" resolves to the 1st of that month, "Period
   to" to its last day, before being sent to the existing date-range
   query params (no backend filter contract changed). A single
   point-in-time filter (Accounting Reports' Trial/AR/AP Aging "As
   at") is NOT part of this convention and stays an exact-day picker,
   since a period doesn't make sense for one instant -- same for every
   ordinary form field that records one date on a document (start
   date, due date, effective date, work date, etc.), which was left
   untouched.
   One trade-off flagged rather than silently exempted: Event Logs is
   a forensic/audit tool where day-level precision has real
   investigative value, and this change means it can now only be
   filtered down to a month, not a specific day. Applied uniformly
   per the "all filtering" instruction rather than guessed as an
   exception -- easy to revert (`frontend/src/pages/EventLogsPage.tsx`)
   if day-level filtering turns out to be needed.
   *Where implemented:* `frontend/src/lib/period.ts` (shared
   `monthStartISO`/`monthEndISO`/`isoToMonth` helpers), `ContractsPage`,
   `OperationsReportsPage`, `AccountingReportsPage`, `EventLogsPage`.
   *Arises in:* Service Contracts, Operations Reports, Accounting
   Reports, Event Logs -- and every future list/report screen with a
   date-range filter, which should follow the same convention.

14.2. **Universal document numbering.** **Status: DECIDED 2026-09-11**
   -- "all main documents need to have a system generated running
   number to be controlled." Contract, Job Order and Service Record
   (the three operational documents with none) now get one via the
   same `DocumentSequence`/`next_document_number` mechanism as every
   accounting document (CON-/JO-/SR-<year>-<seq>). Existing rows were
   backfilled (oldest first per company/year) in the same migration
   that added the columns, and Document Control (already generic)
   picks the three new counters up automatically.
   *Where implemented:* `app/services/numbering.py` (PREFIXES),
   `app/models/{contracts,job_orders,service_records}.py`,
   migration `1c0caa9bdbb3`.
   *Arises in:* Service Contracts, Service Operations, Service
   Records, Document Control.

## 15. Document number format customization and staff photos (raised 2026-09-11)

15.1. **Document Control: customizable prefix and digit padding.**
   **Status: DECIDED 2026-09-11.** Each document kind's running number
   now has a per-company format, editable from Document Control:
   prefix ("front alphabet"), digit padding (e.g. 4 -> "0001"), and
   whether the year is included. A kind nobody has customized keeps
   using the built-in default (PREFIXES in `app/services/numbering.py`,
   4 digits, year included) -- adding this changed nothing for anyone
   who doesn't touch it. A format change only affects numbers issued
   from that point on; every document already numbered keeps the exact
   text it was given (never renamed retroactively).
   *Where implemented:* `app/services/numbering.py`
   (`DocumentNumberFormat`, `format_document_number`),
   `app/routers/document_control.py`, migration `cc953b888c6b`.
   *Arises in:* Document Control, and every module that numbers a
   document (Contracts, Invoices, Bills, JV, POs, Quotations,
   Receipts, Payment Vouchers, Job Orders, Service Records).

15.2. **Staff photos on Support Monitoring.** **Status: DECIDED
   2026-09-11.** `User.photo` holds an optional staff photo (inline
   data URI, same pattern/size cap as `Company.logo`), uploaded from a
   staff member's own Staff Master page. Shown as a circular avatar on
   Support Monitoring and the Staff Master list; falls back to the
   person's initials when no photo is set. No requirement was given
   for cropping/aspect-ratio enforcement, so the raw uploaded image is
   shown `object-fit: cover` inside a circle -- revisit if a specific
   crop/aspect-ratio behaviour is wanted.
   *Where implemented:* `app/models/core.py` (`User.photo`),
   `app/services/monitoring.py`, migration `80a442a466b3`,
   `frontend/src/components/StaffAvatar.tsx`.
   *Arises in:* Staff Master, Support Monitoring.

## 16. Sample staff photos, bigger avatars, menu reordering (raised 2026-09-11)

16.1. **Sample photos are a generated icon, not a real photo --
   environment limitation, not a choice.** Asked for "real person" sample
   photos for the 8-staff demo; this environment's outbound network
   access is a small allowlist of code-library CDNs (confirmed by a
   403 testing a face-image host) and there is no image-generation
   tool available, so neither a real nor an AI-synthetic photo can be
   produced here. `avatar_photo_data_uri()` in `scripts/seed_demo.py`
   generates a flat-icon silhouette instead (pure stdlib PNG encoder,
   no dependency added), purely for sample/demo data -- the real
   feature (uploading an actual photo from Staff Master) is unaffected
   and already fully working. Revisit if a way to source real/
   synthetic photos becomes available.

16.2. **8 sample Company-1 staff.** **Status: DECIDED 2026-09-11.**
   5 more staff (Marcus, Farhana, Kevin, Siti, Bryan) added to
   `seed_demo.py` alongside the original 3 (Nico, Cherish, Wei Ling),
   purely so Support Monitoring has a realistic 8-person view to demo
   -- same roles/Group pattern as the original 3, no new business
   rule. Company 2 (Priya only) is untouched.

16.3. **Support Monitoring: bigger avatars, "Total Job Orders" tile
   removed.** **Status: DECIDED 2026-09-11.** Avatar size on the
   staff cards increased (30px -> 56px); the summary row now shows
   only Open Job Orders, Overdue, Unassigned, Pending Service Records
   and Un-Tested Software Tasks -- the redundant "Total Job Orders"
   tile (sum of open + resolved/closed) was dropped. The backend
   still computes and returns `total_job_orders`; only this screen
   stopped displaying it.

16.4. **Menu bar: choose the link sequence.** **Status: DECIDED
   2026-09-11.** Every link within a sidebar section (Operations /
   Accounts / Maintenance) can be dragged to reorder it -- a per-
   browser display preference (localStorage, one order list per
   section), same posture as the collapse state (#14.1's sibling
   feature): it changes where a link appears, never what a user can
   see (Group Authority-hidden links stay hidden regardless of
   position). Reordering across sections is not supported -- a link
   stays under the module area it belongs to.
   *Where implemented:* `frontend/src/components/NavSection.tsx`,
   `frontend/src/components/Layout.tsx`.
   *Arises in:* every module with a nav link.

---

## 17. Accounts menu default order + Tax/Currency/GL Types moved to Maintenance (raised 2026-09-11)

17.1. **Accounts section default order.** **Status: DECIDED 2026-09-11.**
   Set the default (pre-drag) order of the Accounts sidebar section to
   Dennis's requested sequence: Bank, Sales Quotation, Sales Invoice,
   Receipt Voucher, Accounts Payable, Payment Voucher, Journal Voucher,
   Chart of Accounts, GST and Account Period, Accounting Reports.
   Several existing links were relabeled to match the requested wording
   (Invoices -> Sales Invoice, Receipts -> Receipt Voucher, General
   Ledger -> Journal Voucher, Accounting Periods -> GST and Account
   Period, Bank Master File -> Bank) -- the underlying pages and routes
   are unchanged, only the nav label and position moved. This is still
   just a *default*: per #16.4 each user can drag-reorder their own
   copy, so this only sets what a fresh browser sees.

   Two items in the requested list have no dedicated page of their own,
   so they were mapped onto the closest existing route rather than
   adding a confusing duplicate nav entry -- flag for correction if
   either mapping is wrong:
   - **"Purchase Order"** -> `/accounts-payable`. Purchase Orders are
     already a section of the Accounts Payable page (alongside
     Suppliers and Bills), not a separate route -- a genuine standalone
     PO page/route was not built.
   - **"GST and Account Period"** -> `/accounting-periods` (relabeled).
     The GST F5-style return itself was NOT moved -- it stays a report
     under Accounting Reports (#48), since it's a report output, not a
     period-setup screen.

17.2. **GL Types, Tax Types, Currency Rate Table moved to Maintenance.**
   **Status: DECIDED 2026-09-11.** Per "Those tax type, currency type
   files should be in the Maintenance Section" -- moved out of the
   Accounts section into Maintenance, alongside Setup Lists (their
   closest sibling: all four are reference/master-data maintenance
   screens, not transactional). GL Types was moved along with Tax Types
   and Currency Rate Table even though only the latter two were named
   explicitly, since it's the same kind of reference-data screen and
   was the only one of the three left behind otherwise -- flag if GL
   Types was meant to stay under Accounts.
   *Where implemented:* `frontend/src/components/Layout.tsx`
   (`accountsItems` / `maintenanceItems` arrays). No route or page
   changes -- URLs are unchanged, only which sidebar section links to
   them.

---

## 18. Year-End Closing nav position; Job Orders Resolve/Close was a dead end (raised 2026-09-11)

18.1. **Year-End Closing moved to its own page/nav entry.** **Status:
   DECIDED 2026-09-11.** Per "Year End Closing shift below GST and
   Account Period" -- it used to be a card at the bottom of the
   Accounting Periods page; split out to its own route
   (`/year-end-closing`) and Accounts nav entry, positioned directly
   below "GST and Account Period". The Accounting Periods page now
   just points to it.
   *Where implemented:* `frontend/src/pages/YearEndClosingPage.tsx`
   (new), `frontend/src/pages/AccountingPeriodsPage.tsx` (trimmed),
   `frontend/src/components/Layout.tsx`, `frontend/src/App.tsx`.

18.2. **Job Orders could never actually be Resolved or Closed --
   fixed.** **Status: DECIDED 2026-09-11, flag if the assumptions
   below are wrong.** Requested as "Run through Job Orders and see
   what to touch up"; this is the finding, not a UI polish item. The
   `RESOLVED`/`CLOSED` statuses and the `resolved_at` column existed on
   the model from the very first build, and the Dashboard/Support
   Monitoring "open job orders" counts already excluded them (see
   `OPEN_STATUSES` in `app/services/monitoring.py`) -- but no endpoint
   ever set a Job Order to either status. Every Job Order was
   permanently stuck at Open or Assigned; the documented workflow
   (docs/workflows.md step 10: "Job Order is resolved and closed") was
   simply never wired up.

   Added `POST /job-orders/{id}/resolve` (Open/Assigned -> Resolved,
   stamps `resolved_at`), `.../close` (Resolved -> Closed), and
   `.../reopen` (undoes either, back to Assigned/Open -- owner-only,
   mirroring the Accounting Period reopen pattern, so a mistake never
   needs a direct database edit). Two defaults, not confirmed business
   rules -- flag if wrong:
   - **Who can resolve/close:** same access as the rest of this page
     (EDIT on service_operations, same as Assign/Due-date) -- no extra
     approval gate, since the workflow doc doesn't name a specific
     approver for this step the way it does for Excess Review (Nico/
     Cherish, SRV-004/011).
   - **No precondition check** (e.g. all Service Records must be
     Approved first) before allowing Resolve -- the workflow doc lists
     billing/invoicing as a prior step but doesn't say the system must
     enforce it before Resolve is allowed.
   *Where implemented:* `backend/app/routers/job_orders.py`,
   `backend/app/schemas/schemas.py` (`resolved_at` now returned),
   `frontend/src/pages/JobOrderDetailPage.tsx`,
   `frontend/src/lib/api.ts`. No migration needed -- the column already
   existed.

   **Superseded the same day -- see #19.1 below.** The explicit status
   list given afterwards ("open, closed, assigned, void") replaced this
   manual Resolve/Close pair with an auto-close rule; this entry is
   kept for history, not as the current design.

---

## 19. Job Order status rework, Service Record deduction workflow,
   Company/Individual relationships, Job Order printing (raised 2026-09-11)

19.1. **Job Order status: open/assigned/closed/void; auto-close
   replaces manual Resolve/Close.** **Status: DECIDED 2026-09-11.**
   RESOLVED is gone; VOID is new (a manual dead-end for a job that
   should never have been raised -- duplicate, raised in error --
   distinct from a normally finished job). A Job Order now auto-closes
   when its **most recently submitted** Service Record is both
   Approved and marked Completed ('C', not Uncompleted 'U') --
   `maybe_auto_close_job_order()` in
   `backend/app/services/service_records.py`. Only the latest record
   matters, so earlier Uncompleted visits don't block closing once the
   final visit is done and approved. Void requires a reason (audited);
   Reopen (owner-only, undoes either Closed or Void back to
   Assigned/Open) still exists for correcting a mistake without a
   direct database edit. Once Closed or Void, Assignment/Due-date/Log-
   a-Service-Record are hidden on the page and rejected server-side too
   (reopen first) -- a gap found while building this, not explicitly
   requested, but an obvious consequence of "finished/cancelled work
   shouldn't take new entries."
   *Migration:* `a025ca222e9c` rebuilds the `job_order_status` Postgres
   enum (no `DROP VALUE` exists) and renames `resolved_at` ->
   `closed_at`.

19.2. **Service Record: Completion (C/U), After-hours flag, and
   approver-keyed Deduction minutes.** **Status: DECIDED 2026-09-11.**
   `completion_status` is set by whoever submits the record (does this
   visit finish the job, or is another one needed) and drives 19.1's
   auto-close. `is_after_hours` is a manual tick (no office-hours/
   public-holiday calendar exists in this build to derive it from).
   `deducted_minutes` is a new field the approver keys in herself at
   approval time -- distinct from the objective `rounded_minutes` log
   -- confirmed via "actual is 240mins, deducted is 220mins or
   360mins." A new page, **Service Record Approval**
   (`/service-record-approval`, nav entry below Service Records),
   replaced the old one-click Approve button (which had no way to
   collect a minutes value) -- it prefills a *suggestion* per 19.3 but
   the approver can type any value.

19.3. **Urgent (x1.5) / After-hours-Weekend-Holiday (x2.0) deduction
   multiplier -- suggestion only, not enforced.** **Status: DECIDED
   2026-09-11.** `Job Order.is_urgent` is a manual tick (toggle on the
   Job Order detail page or at creation). The suggested deduction
   minutes on the Approval page is `rounded_minutes x` the higher of
   the two multipliers when both apply (confirmed: not stacked/
   multiplied together, so Urgent + after-hours suggests x2.0, not
   x3.0) -- `suggested_deduction_minutes()` in
   `backend/app/services/service_records.py`. This only changes what
   number is prefilled; the approver's typed-in value is what's
   actually deducted and posted, so getting the suggestion formula
   slightly wrong has no data-integrity consequence, only a
   convenience one. The multiplier does **not** touch the excess-hour
   billing rate (SRV-008's blended rate) -- flag if urgency/after-hours
   was meant to affect the dollar rate charged on excess time too, not
   just contract-hour-pool minutes consumed.

19.4. **Job Order printing.** **Status: DECIDED 2026-09-11.** The
   listing + filters this asked for already existed
   (`JobOrdersPage.tsx`); added the printing half -- a Print link per
   row and on the detail page, opening `/job-orders/:id/print`
   (`JobOrderPrintPage.tsx`), following the same pattern as every other
   printed document in the app (Quotation, Invoice, Receipt, Payment
   Voucher).

19.5. **Due date / Assignment shown side by side.** **Status: DECIDED
   2026-09-11.** Two small cards side by side on the Job Order detail
   page instead of stacked full-width -- pure layout, no field or
   behavior change.

19.6. **"Company / Individual" relationships -- rename + links, not a
   restructure.** **Status: DECIDED 2026-09-11 (confirmed scope: rename
   the label and add relationship links between existing records,
   *not* split Customer into two distinct entity types).** The
   Customers nav entry, page title, and table heading are relabeled
   "Company / Individual" -- the underlying `Customer` table/fields are
   unchanged (it already had `customer_type` = company/individual).
   New: `CustomerRelationship`, an undirected link from one Customer to
   another Customer **or** to a specific Contact at another company
   (covers all three levels asked for -- company-level and individual-
   level both use `to_customer_id`, since the level already follows
   from that Customer's own `customer_type`; company-contact-level uses
   `to_contact_id`; exactly one is set). `relationship_type` is free
   text (no fixed taxonomy was given, matching how `Customer.tags`
   already works) with a few suggested values in the UI. Undirected by
   default (one row, same label shown from either side) rather than a
   directional pair like "Parent of"/"Subsidiary of" -- a pragmatic
   default, flag if a directional model was actually wanted. "Can be
   customer or supplier or dealer" is noted as page copy, not built as
   a merge with the separate Supplier entity (Accounts Payable) --
   that's a materially bigger change and wasn't the confirmed scope.
   Every other screen that references "Customer" (Job Orders,
   Contracts, Invoices, etc.) keeps that wording -- only the Customers
   module's own nav/page labels changed, to keep this a bounded rename
   rather than an app-wide sweep.
   *Where implemented:* `backend/app/models/company_individuals.py`
   (`CustomerRelationship`), `backend/app/routers/company_individuals.py`,
   `backend/app/schemas/schemas.py`, `frontend/src/pages/
   CustomerDetailPage.tsx`, `frontend/src/pages/CustomersPage.tsx`,
   `frontend/src/components/Layout.tsx`. Migration: `c2b41b06fd17`.

19.7. **Incident Module -- deferred.** Requested ("log calls, route to
   Sales Quotation / Job Order / Software Tasks / a callback, future
   Outlook integration") but explicitly deferred to its own follow-up
   pass per Dennis's instruction ("settle company/individual first
   then new incident module later"). Confirmed so far: converting an
   Incident should auto-create the real linked record (not just route/
   assign) -- to be designed in full when that pass starts. Logged
   alongside the Support Staff Mobile App (see
   [docs/planned-work.md](docs/planned-work.md)) as confirmed-but-not-
   yet-built work.

---

## 20. Ops Dashboard: personal task tracker per staff member (raised 2026-09-11)

Requested as "Create a staff individual ops dashboard based on the
staff login," modeled on a sample screenshot (categories of tasks, a
status/next-action/owner/due/follow-up table, stat tiles, a status
legend, filters, expand/collapse, export).

20.1. **Both freeform tasks AND a real-ERP-data rollup, confirmed
   2026-09-11.** Two distinct sections on one page, not merged into one
   data model:
   - Freeform: `OpsTaskCategory`/`OpsTask` -- manually created
     categories and tasks, independent of Job Orders/Contracts, with
     the exact columns in the sample (status, next action, owner
     label, due label, follow-up staff, follow-up date). `owner_label`
     and `due_label` are free text, not foreign keys/real dates --
     the sample itself mixes values like "Dennis + Bot" and "Month-end"
     with real names/dates in those columns, so a strict type would
     reject exactly what the sample shows.
   - Real-ERP rollup: read-only "My open Job Orders" and "My Software
     Tasks" sections, computed by filtering existing tables
     (`JobOrder.assigned_to_user_id`, `SoftwareTask.assigned_programmer_id`
     /`tester_user_id`) for the viewed staff member -- no new model.

20.2. **Visibility: everyone sees their own; Owner/Service
   Lead/Sales Manager can also view AND edit anyone's, confirmed
   2026-09-11.** Mirrors the "manager-ish" role set already used for
   Service Record approval and Excess Review (`MANAGER_ROLES` in
   `app/routers/ops_dashboard.py`) rather than inventing a new role
   concept. A manager gets a "Viewing" dropdown to switch to any staff
   member's dashboard; anyone else sees no such control.

20.3. **No in-app "Reset seed" button.** The sample screenshot has one,
   but it's a real feature now, not a demo tool -- a button that wipes
   a staff member's actual task list would contradict "never
   permanently delete important business or financial records" in
   spirit (these aren't financial records, but the same caution
   applies). Sample/demo content instead comes from `seed_demo.py`
   like every other module's demo data, flagged `is_sample=True` and
   shown with a "(sample)" label.

20.4. **"Edit staff list" links to Staff Master instead of a new admin
   screen.** Staff Master already is the canonical place to manage
   staff accounts; duplicating that here would just be two places that
   can drift out of sync. The button in the sample is treated as
   "manage who can appear in the follow-up-staff dropdown," which is
   exactly what Staff Master already does.

20.5. **Export JSON** downloads the currently-loaded dashboard (all
   categories/tasks/rollups for whoever is being viewed) as a `.json`
   file, client-side -- no backend export endpoint, since there's
   nothing to compute beyond what's already fetched.

*Where implemented:* `backend/app/models/ops_tasks.py`,
`backend/app/routers/ops_dashboard.py`, `backend/app/schemas/schemas.py`,
`frontend/src/pages/OpsDashboardPage.tsx`, nav entry ("My Ops
Dashboard") in `frontend/src/components/Layout.tsx`, module key
`ops_dashboard` in Module Control/Group Authority (all four default
Groups get FULL). Migration: `08ceed0e5b5d`.

20.6. **Dark theme re-keyed to a navy palette; new status colours,
   confirmed 2026-09-11.** "Change the background color codes to match
   the UI Color sample" -- read as the structural dark-theme colours
   (`--bg`/`--surface`/`--border`/`--text`/`--text-muted`), not the
   brand accent: `--accent` stays the company maroon confirmed earlier
   (#20 in the completed-tasks history, "black, maroon, white"), since
   changing the background palette to match a new sample and abandoning
   the confirmed brand accent color are two different asks and only
   the former was made. The Ops Dashboard's 5 statuses now each get a
   distinct colour (amber/blue/purple/red/green, matching the sample)
   via two new token pairs (`--info-*` for "In progress", `--watch-*`
   for "Watch") alongside the existing warn/ok/danger tokens -- applied
   both to the legend badges and directly to each task's status
   `<select>`, so it reads as a colour-coded pill rather than a plain
   dropdown. Light theme is untouched.
   *Where implemented:* `frontend/src/index.css` (theme tokens +
   `.badge.status-*`), `frontend/src/pages/OpsDashboardPage.tsx`
   (`STATUS_BADGE`, `STATUS_SELECT_STYLE`).

---

## 21. Auto-hide sidebar on the two dashboards (raised 2026-09-11)

Requested as "for the first 2 dashboard, when we go in can you adjust to
hide the menu bar, so we can display more wider on the screen." Applies
to Company Dashboard (`/`) and My Ops Dashboard (`/ops-dashboard`) --
both are stat-tile/table-heavy pages that benefit from the extra width;
no other page was asked for.

21.1. **Route-driven, not a sticky preference, DECIDED by implementation.**
   The sidebar auto-hides on landing on either of those two routes and
   `.main` drops its 1000px cap so content uses the full window width. A
   "☰ Menu" button (top-left of the topbar, only shown on these two
   routes) lets you peek the sidebar back open to navigate elsewhere,
   without leaving the page; it relabels to "✕ Hide menu" while open.
   Leaving and coming back to either dashboard always re-hides it -- the
   peek is a per-visit override, not a remembered setting, since the
   request was to default to the wide layout on these pages, not to let
   the sidebar disappear everywhere once toggled. Every other page is
   unaffected: sidebar always visible, no toggle button rendered.
   *Where implemented:* `frontend/src/components/Layout.tsx`
   (`WIDE_DASHBOARD_PATHS`, `sidebarPeek` state reset on route change),
   `frontend/src/index.css` (`.app-shell.sidebar-hidden`, `.main-topbar-left/-right`).

---

## 22. Purchase Order: own nav item, confirm-and-import to AP, Email/WhatsApp (raised 2026-09-12)

Requested as "let's work on purchase order on the menu bar above accounts
payable... Eventually is to confirm and import to AP... Printing of PO
and also email out to supplier, do u have also whatsapp out the PO."

22.1. **Own page and nav item, DECIDED by implementation.** Purchase
   Order moved off the Accounts Payable page onto its own page/route
   (`/purchase-orders`), with its own nav item directly above Accounts
   Payable -- matching where Dennis pointed. Suppliers (including the
   new phone field, see 22.4) are still managed on the Accounts Payable
   page; Purchase Order only reads that list.
   *Where implemented:* `frontend/src/pages/PurchaseOrdersPage.tsx`,
   `frontend/src/components/Layout.tsx`.

22.2. **"Confirm" = the existing PUR-001 approval; "import to AP" is a
   new one-click action, DECIDED by implementation.** A PO already had
   an Approve step (PUR-001). "Import to AP" is new: once approved, it
   creates the matching bill automatically (same supplier/description/
   amount), 2-way matches it against the PO (PUR-002) and auto-approves
   it for payment (PUR-003) -- instead of re-typing the same PO into
   "Record a supplier bill" by hand. Each PO can only be imported once;
   re-clicking (or trying via a second bill) is blocked with a clear
   error naming the bill it was already imported as.
   *Where implemented:* `app/services/payables.py`
   (`assert_po_importable_to_ap`), `app/routers/payables.py`
   (`import_purchase_order_to_ap`), `PurchaseOrder.bills` relationship
   (no new column -- reuses the existing `purchase_order_id` FK on
   `supplier_invoices` to detect a prior import).

22.3. **Print, DECIDED by implementation, following the existing
   Invoice/Quotation pattern exactly.** A dedicated print page
   (`PurchaseOrderPrintPage.tsx`) with "PDF (Print)" (browser's own
   Print -> Save as PDF) and "Word" (server-side .docx) -- no new
   pattern introduced.

22.4. **Email PO: real server-side send with the PO as a PDF attachment,
   asked and answered.** Asked because the app had zero email-sending
   infrastructure anywhere (Invoice/Quotation only ever offered Print).
   Dennis chose real server-side send over a mailto: draft. Implemented
   over plain SMTP (stdlib `smtplib`, no new pip dependency) via
   `app/services/mailer.py`; unconfigured by default so "Email" fails
   with a clear message until `backend/.env` carries real SMTP settings
   (see DEV_SETUP.md) -- one shared mailbox for the whole install, not
   per company. The PDF attached is the same `purchase_order_to_docx`
   template used for "Word", converted via LibreOffice headless
   (`soffice --convert-to pdf`, see `app/services/pdf_convert.py`)
   rather than a second PDF layout built with e.g. reportlab -- one
   template can't drift from the other. This adds LibreOffice Writer as
   a **system** dependency on whatever machine runs the backend (not a
   pip package) -- flagged here as a real addition to the approved
   architecture, worth knowing about before deploying "Email PO" to a
   production VPS. `libreoffice-core`/`-common` alone is not enough; the
   `-writer` package specifically is required (discovered live: without
   it, conversion fails with "source file could not be loaded").

22.5. **WhatsApp PO: `wa.me` chat link, asked and answered.** Same
   reasoning -- no WhatsApp integration existed. Dennis chose the
   zero-dependency option over a real WhatsApp Business API integration
   (which would need a vendor, a verified business number, and paid API
   access -- a real vendor decision, not made here). The "WhatsApp"
   button opens `https://wa.me/<supplier phone, digits only>?text=...`
   with a short pre-filled message; the PDF itself is attached manually
   in the chat, same one extra step as Email's PDF used to be before
   22.4. Needs the supplier's phone number, so `Supplier.phone` was
   added (new nullable column, migration `ca1ddfca6a83`) -- entered on
   the Accounts Payable page.

---

## 23. Supplier consolidated into Company/Individual -- no separate master (raised 2026-09-12)

Requested as: "when talking about supplier, remember to use the same
company/individual file, do not add or reinvent a new one again" --
directly reversing the previous session's own `Supplier` table (added
under item 22) rather than an open question, so recorded as **DECIDED**
straight away.

23.1. **DECIDED.** The standalone `suppliers` table and `Supplier` model
   are removed. A supplier is now a `Customer` (Company/Individual)
   record with `is_supplier=True` -- a new role flag alongside the
   existing (now also explicit) `is_customer` flag, so one record can be
   a customer, a supplier, or both. Suppliers are created/edited on the
   Company/Individual page (or its detail page), never on Accounts
   Payable or Purchase Order, which now only *read* that same list
   filtered to `is_supplier=true`.
   Migrated the one seeded supplier (CloudHost Infrastructure) into
   `customers` **keeping its original id**, so every existing
   `purchase_orders`/`supplier_invoices`/`supplier_payments.supplier_id`
   value kept working unchanged -- only the foreign key's target table
   moved, from `suppliers` to `customers` (migration `e2f33975e091`).
   Finance Team's `company_individual_management` Group Authority was raised from
   VIEW to FULL (seed_demo.py), since onboarding a new supplier now
   needs edit rights on Company/Individual, matching what
   `accounts_payable: FULL` implied before the merge.
   *Where implemented:* `app/models/company_individuals.py` (`is_customer`,
   `is_supplier`), `app/models/payables.py` module docstring, `app/routers/company_individuals.py`
   (`is_supplier` filter), `app/routers/payables.py` (`_supplier_or_404`),
   `frontend/src/pages/CustomersPage.tsx` / `CustomerDetailPage.tsx`
   ("Is Supplier" checkbox + Roles column), `PurchaseOrdersPage.tsx`,
   `AccountsPayablePage.tsx`, `PaymentVoucherPage.tsx`.

23.2. **Found and fixed while touching this code, DECIDED by
   implementation (bug, not a decision):** `GET /purchase-orders/{po_id}`
   had been registered (previous session) before the static
   `/purchase-orders/export.csv` and `/export.xlsx` routes, so FastAPI
   matched `export.csv` as a `po_id` path parameter first and failed
   UUID parsing -- the Purchase Order CSV/Excel export buttons were
   silently broken. Reordered so static routes are registered before the
   `{po_id}` ones, the routing convention every other router in this
   codebase already follows.

---

## 24. Email/WhatsApp rolled out to 6 more document types (raised 2026-09-12)

Requested as: "once done for PO, please do the same for Operation
Service Rec, Acct Sales Quote, Sales Invoice, Receipt, Payment, Acct
Report Statement of Accounts." Same pattern as item 22.4/22.5 (real SMTP
send with a PDF attached via `app/services/document_email.py`, a shared
helper factored out of the PO-specific code; `wa.me` links for WhatsApp)
applied to: Service Records, Sales Quotation, Sales Invoice, Receipt
Voucher, Payment Voucher, and Statement of Accounts.

24.1. **Service Record and Statement of Accounts had no print/export
   form at all before this, DECIDED by implementation.** Both needed a
   new `docx_forms.py` template built from scratch (`service_record_to_docx`,
   `statement_to_docx`) plus, for Service Record, a new print page
   (`ServiceRecordPrintPage.tsx`) and `GET /service-records/{id}` (no
   single-record fetch existed either). Statement of Accounts has no
   dedicated print page -- its existing inline panel (on
   `InvoicesPage.tsx`, opened via "Statement" from the AR Aging table)
   gained "Download (Word)" / "Email" / "WhatsApp" buttons directly,
   rather than adding a whole new route, since that panel already shows
   exactly what the document contains.

24.2. **Who receives it, DECIDED by implementation.** Sales
   Quotation/Invoice/Receipt/Statement email the Customer on the
   document. Payment Voucher emails the supplier (a Customer with
   `is_supplier=true`, item 23). Service Record emails the customer on
   the Job Order the record was logged against (`job_order.customer_id`)
   -- there being no more specific "who to notify" concept on a Service
   Record itself.

---

## 25. `Customer` renamed to `CompanyIndividual` throughout the source code (raised 2026-09-12)

Requested as: "Can u change all Customer labeling in the source code to
Company/Individual also... if not later more confusing...." -- item 20.1
(#20 in the earlier list, i.e. the Supplier-consolidation work) had
already introduced "Company/Individual" as the user-facing name; this
extends that rename to the identifiers themselves.

25.1. **DECIDED.** The `Customer` model, its file
   (`app/models/customers.py` -> `app/models/company_individuals.py`),
   its router (`app/routers/customers.py` -> `company_individuals.py`,
   `app/routers/customer_groups.py` -> `company_individual_groups.py`),
   the frontend pages (`CustomersPage.tsx` -> `CompanyIndividualsPage.tsx`,
   `CustomerDetailPage.tsx` -> `CompanyIndividualDetailPage.tsx`), and
   every compound identifier built on the name (`CustomerType`,
   `CustomerGroup`, `CustomerRelationship`, `CustomerStatement`, the
   `api.*Customer*` functions, etc.) are renamed to the `CompanyIndividual`
   family. The DB tables (`customers`, `customer_groups`,
   `customer_relationships`) are renamed to match (migration
   `95d1cda707de`, table renames only -- no data touched, so every
   existing row and id is preserved). The API route prefix moves from
   `/api/customers` / `/api/customer-groups` to `/api/company-individuals`
   / `/api/company-individual-groups`, and the frontend route from
   `/customers` to `/company-individuals`.

25.2. **What deliberately did NOT change, DECIDED by implementation, to
   bound the blast radius (same precedent as item 23's Supplier merge):**
   - Every FK/role-style **column** name stays as-is: `customer_id`,
     `supplier_id`, `from_customer_id`, `to_customer_id`,
     `customer_group_id`, `legacy_customer_code`, `is_customer`,
     `customer_type` (and its Postgres enum, still named `customer_type`).
     Only the table a FK column *points at* moved.
   - The internal Group Authority module **key** moved to
     `company_individual_management` (migration `95d1cda707de` also
     repoints every existing `modules`/`company_modules`/
     `group_module_authorities` row so no group silently loses access),
     but the module's confirmed **display name stays "Customer
     Management"** -- that name is the one already used throughout
     [module-map.md](module-map.md) #4 and the other requirements docs,
     and this rename is about source-code identifiers, not renaming an
     already-confirmed business-area name.
   - Generic English prose that uses "customer" as a role/relationship
     word (an invoice's "customer", "email the customer", code comments
     describing that role) is left alone -- only text that named the
     master file/screen itself (labels, headings, button text, API error
     details that read as one squashed word straight after a mechanical
     find-and-replace, e.g. a stray "CompanyIndividual Management" or
     "Add customer") was corrected, to "Company / Individual" matching
     the already-established nav label.

25.3. **Migration program (item 64/planned-work.md #6) mapping updated
   to match:** its table now points at `app/models/company_individuals.py`
   / `CompanyIndividual` rather than the pre-rename path/name.

---

## 26. Reference Monitor Module -- GL sub-codes under one Chart of Accounts row (raised 2026-09-12)

Requested as: a Ledger Code (e.g. GL 45001 "Sales of Software Revenue")
needs to break down into several named sub-codes for document selection
-- SLS-WEBSOFT-IMPLEMENTATION, SLS-WEBSOFT-SERVICE, SLS-WEBSOFT-STOCK,
SLS-WEBSOFT-CUSTOMIZATIONS, all posting to the same account -- presettable
on a Product, and "eventually" posting the captured code into General
Ledger transactions.

26.1. **DECIDED, first slice built.** A new `ReferenceCode` model
   (`app/models/reference_codes.py`) is a plain child of one `Account`
   row: `company_id`, `account_id` (FK to `accounts.id`), `code`, `name`,
   `is_active`. Maintained on its own screen, **Reference Monitor**
   (`frontend/src/pages/ReferenceCodesPage.tsx`, under Maintenance,
   same `finance_accounting` module authority as Chart of Accounts) --
   mirrors the existing Chart of Accounts screen's create/inline-rename/
   deactivate pattern. New migration `37381fdd5ae6`.

26.2. **DECIDED.** `Product` gets an optional `default_reference_code_id`
   (set from the Product Catalog page, a new dropdown next to the
   existing fields, also editable inline per row). `QuotationLine` gets
   an optional `reference_code_id`, auto-filled from the chosen
   product's default when a Sales Quotation line is created
   (`POST /api/quotations`), but always overridable via a dropdown on
   the line itself. Quotation is the only document type in this system
   with real per-line item selection today (Invoice/PO/Bills are a
   single amount + description, no lines) -- see
   docs/module-map.md/system-architecture.md for the wider document
   model -- so it is the only document wired up in this first slice.

26.3. **NOT built, DECIDED by implementation to avoid guessing:** actual
   posting of a captured reference code into General Ledger transactions
   (`JournalEntry`/`JournalLine`, `app/models/accounting.py`). No
   document type in this system auto-posts to the GL today -- Journal
   Vouchers are entered manually only (`app/routers/ledger.py`) -- so
   there is nothing yet for a reference code to drive. The "eventually
   post to Chart of Accounts transactions" half of the request is
   recorded here as confirmed future scope, not guessed at now. Real
   open questions once GL auto-posting exists for any document: whether
   a reference code maps 1:1 to a fixed debit/credit rule, whether every
   document type or only some auto-post, and how a manually-entered
   Journal Voucher interacts with a reference-coded line on the same
   transaction.

## 27. Staff password policy, forced first-login change, email OTP, and PDPA consent/data-expiry on Company/Individual (raised 2026-09-12)

Requested as: "user staff password have to use complex password like
alphanumeric... New staff user for the first time to force them change
own password, enhance security with OTP upon login either email or
handphone whatsapp"; and separately "For PDPA Purposes, contact
company/individual file need to have a section to keep checkbox record
date/time when they esigned the PDPA Agreement and filed in the
system, all data relating to this customer have an data expiry date
and after the expiry date, we need to archive them somewhere."

27.1. **DECIDED, built.** Password complexity: at least 8 characters
   (already enforced), plus at least one letter and one number
   (`app/services/auth.py::validate_password_complexity`). No
   uppercase/special-character rule was given, so none is assumed.
   Enforced on staff creation, admin password reset, and self-service
   change-password.

27.2. **DECIDED, built.** Forced change on first login:
   `User.must_change_password` defaults `True` for every new staff
   account and is set back to `True` on every admin-initiated password
   reset (a temporary password should never outlive one sign-in). A
   user in this state gets a short-lived `password_change`-purpose
   token from `/api/auth/login` instead of a real access token, and
   must call `/api/auth/change-password` before continuing. Demo/seed
   accounts (`scripts/seed_demo.py`) are explicitly seeded with
   `must_change_password=False` so the existing walkthrough login
   isn't interrupted -- a real staff account created from Staff Master
   always goes through this.

27.3. **DECIDED with Dennis (email now, WhatsApp later).** OTP channel:
   email OTP is built (`LoginOtp` model, 6-digit code, SHA-256-hashed,
   10-minute expiry, 5-attempt cap), issued via the existing SMTP
   mailer (`app/services/mailer.py`) whenever `mailer.is_configured()`
   is true. If SMTP isn't configured, login skips the OTP step
   entirely rather than locking every user out of an unconfigured
   dev/demo environment. **WhatsApp OTP is NOT built** -- it needs an
   automated send-and-verify integration (a WhatsApp Business API
   account via Twilio or Meta's Cloud API); today's WhatsApp usage
   elsewhere in this system is only manual `wa.me` links opened by a
   staff member, which cannot deliver a code unattended. Recorded here
   as confirmed future scope once that account exists.

27.4. **DECIDED (security design).** Every JWT this system issues now
   carries a `purpose` claim -- `"access"` for a real bearer token,
   `"password_change"` / `"otp"` for the two short-lived intermediate
   tokens the login sequence hands back. `decode_access_token` rejects
   anything whose purpose isn't `"access"`, so an intermediate token
   can never be replayed against a protected endpoint even if it
   leaked (verified: calling `/api/auth/me` with a `password_change`
   token returns 401).

27.5. **DECIDED with Dennis (soft-archive in place).** PDPA on
   Company/Individual: `pdpa_consent_given` (bool) + `pdpa_consent_at`
   (server-stamped, never client-supplied) record the e-signed PDPA
   Agreement checkbox, set only via the dedicated
   `POST /api/company-individuals/{id}/pdpa-consent` endpoint (not the
   general PATCH) so the timestamp is always trustworthy. Every change
   is written to the existing audit trail.

27.6. **DECIDED with Dennis (soft-archive in place, not a separate
   archive schema or export-and-delete).** `data_expiry_date` (optional,
   per record) flags when a Company/Individual's data should be
   archived. `is_archived` / `archived_at` follow the exact same
   pattern as the existing `is_active` flag -- all data stays in the
   same database row, per CLAUDE.md's "never permanently delete" rule
   -- toggled via `POST .../archive` and `POST .../unarchive`. Archived
   records are hidden from every normal list/picker even with "show
   inactive" on; a separate "Show archived" opt-in reveals them.
   **NOT built: automatic archiving.** There is no background job
   infrastructure in this system (every other lifecycle action --
   Year-End Closing, deactivation -- is a deliberate staff click), so
   a record past its `data_expiry_date` is flagged on its own page
   (a "Past expiry -- archive this record" badge) for a staff member to
   archive; it is not swept up automatically. Revisit if/when this
   system gains a scheduled-job runner.

## 28. PDPA signed-agreement viewing, archive-button gating, login video panel, and forgot-password OTP (raised 2026-09-12, follow-up to #27)

Requested as: "PDPA Need to be able to see the signed agreement, the
archive button is only to appear after the expiry date... pls take
care of the security too... LOGIN page - The right advert panel sample
picture can change to video instead.. Need to have a forget password,
and OTP also."

28.1. **DECIDED, built.** "See the signed agreement": a new
   `pdpa_agreement_document` field on Company/Individual (image or PDF,
   data URI, same inline-storage pattern as Company.logo/User.photo,
   capped at ~2 MB) uploaded and previewed from the PDPA & Data
   Retention card -- an image renders inline, a PDF embeds inline.
   Security taken as: (a) content-type + size validated server-side,
   (b) gated at the same `company_individual_management` access level
   as every other field on the record (this system has no per-field
   permission granularity -- see the RBAC section of
   system-architecture.md), (c) never reachable from any
   unauthenticated endpoint, (d) every upload/removal is written to
   the audit trail (never the file content). If "the security" meant
   something more specific (e.g. restricting this one field to a
   narrower group than the rest of the record, or encryption at rest),
   that hasn't been built -- flag it and it can be added.

28.2. **DECIDED, built.** "Archive button only appears after the
   expiry date": a UI-level gate on the CompanyIndividual detail page,
   not a backend restriction -- `POST .../archive` itself still accepts
   a call regardless of date (an admin can still correct a mistake or
   close an account early for another reason), and Unarchive is always
   available once archived. Revisit if a hard backend rule turns out
   to be what was meant.

28.3. **DECIDED, built (a placeholder, not a real ad).** Login page
   promo panel changed from static text to a looping muted video
   (`Login.tsx`'s `PROMO_VIDEO_URL`) with a short text caption below
   it. No real advertisement video exists yet, so a well-known CC0
   sample clip (hosted on MDN's own infrastructure) stands in --
   swap the URL for the real one once available. Chosen as an external
   URL rather than building video file upload/storage: a video is far
   too large to hold inline the way the logo/photo/PDPA document
   above do, and no video-hosting infrastructure exists in this system
   -- introducing one is a bigger architecture decision than this
   request asked for. The panel falls back to the plain gradient +
   caption (its original look) if the video URL doesn't load in a
   given deployment, so it never shows a broken box.

28.4. **DECIDED, built.** "Forget password, and OTP also": a
   self-service email+OTP flow, separate from the login sequence --
   `POST /api/auth/forgot-password` (email) always returns the same
   generic message whether or not the account exists (prevents
   enumerating staff emails), and only actually emails a 6-digit code
   when it does and SMTP is configured; `POST /api/auth/reset-password-otp`
   (email + code + new password) sets the new password directly once
   the code matches -- no separate link/token exchange, since email +
   code together already prove account control. Reuses the `LoginOtp`
   table from #27.3, now with a `purpose` column ("login" vs
   "password_reset") so a code emailed for one flow can never complete
   the other. If email delivery isn't configured, this flow has no way
   to reach the user (same limitation as login OTP) -- there is
   currently no alternative (e.g. a security-question fallback), since
   none was requested.

## 29. Sidebar hidden app-wide + a smaller ad-video banner on every page (raised 2026-09-12)

Requested as: "After login successfully, all modules, hide menu bar,
then have smaller tall banner on the right for advertisement video."

29.1. **DECIDED, built.** Extends the 2026-09-11 decision that hid the
   sidebar by default on just the two dashboards ("for the first 2
   dashboard, when we go in ... hide the menu bar, so we can display
   more wider on the screen") to every page in the app: the sidebar is
   now hidden by default everywhere, with the existing "☰ Menu" button
   in the topbar peeking it back open on any page (it re-hides again on
   the next navigation, same as it always did on the two dashboards --
   see `Layout.tsx`). Sign-out and the company logo/name live inside
   that sidebar, so they now need one click to reach from anywhere,
   same tradeoff already accepted for the two dashboards, just applied
   consistently everywhere instead of as a special case.

29.2. **DECIDED, built.** The Login page's advertisement video
   (2026-09-12, see #28.3) is now also shown -- smaller -- as a
   permanent tall banner on the right of every authenticated page, in
   the width the sidebar's hiding freed up. Extracted into a shared
   `components/PromoVideoPanel.tsx` so both placements play the same
   clip/caption and degrade the same way if the video can't load.
   Explicitly hidden from print output (`@media print`), same as the
   sidebar and topbar -- a printed invoice/receipt/etc. must never
   carry it.

## 30. Announcements & Ad Banner admin screen (raised 2026-09-12)

Requested as: "Is there a place for me to set all these advertisements
or latest updates and push publish?" -- following #28.3/#29.2, where
the promo video and "What's New" items were still hardcoded in
`PromoVideoPanel.tsx`.

30.1. **DECIDED with Dennis, built.** Save = live immediately, no
   separate draft/publish step -- matches every other admin screen in
   this system (Company Setup, Module Control, Tax Types, ...). A new
   **Announcements & Ad Banner** screen under Maintenance
   (`core_administration`, FULL access) lets Dennis set the video URL
   and add/edit/reorder/hide/delete the "What's New" items; both are
   global (`Announcement`, `AdBannerSettings`), not company-scoped --
   see app/models/announcements.py's docstring for why (these are
   announcements about the software itself, and the Login page shows
   them before any company is even selected). `GET /api/announcements/
   public` is the one unauthenticated read both the Login page and the
   signed-in ad banner use.

30.2. **DECIDED by implementation.** The video field takes a URL, not
   an upload -- consistent with #28.3's reasoning (a video is too large
   to hold inline, and this system has no video-hosting
   infrastructure). "What's New" items support a real delete (not just
   the usual soft-delete/is_active pattern), since these are marketing
   blurbs with no downstream references, not audited business/
   financial records; `is_active` is still offered as a quick hide/
   show without losing the text.

30.3. **Known limitation, not raised as a question:** "push publish"
   takes effect on the next time the panel loads (a fresh visit, login,
   or page refresh) -- there is no live-push mechanism in this system
   (no websockets/polling infrastructure anywhere), so a tab that is
   already open when a change is saved won't update until it reloads.
   Flagged in the admin screen's own help text rather than treated as
   a gap to close, since nothing else in this system live-updates an
   open tab either.

## 31. Bank Book: separate ledger, not GL-derived (raised 2026-09-12)

Requested as: "Bank Opening Balances / Bank Transaction Debit & Credit
and Ledger Balances / Bank Reconciliation" -- Dennis asked for all
three.

31.1. **DECIDED with Dennis, built.** Before this could be built, one
   real fork existed: the General Ledger's `JournalEntry`/`JournalLine`
   already has `VoucherType.RECEIPT`/`PAYMENT` and `source_type`/
   `source_id` fields, but nothing currently posts to them -- only the
   manual Journal Voucher screen writes a `JournalEntry` (see #4b.2:
   which account a sales invoice or receipt should post to is still
   undecided). Asked Dennis whether the Bank Book's ledger should read
   from GL Journal Vouchers, or be its own separate ledger. **Dennis
   chose a separate Bank Book**, not the GL-derived option recommended.
   This means the Bank Book's running balance and the General Ledger's
   Bank/Cash-at-bank account balance are two independent figures that
   can drift apart -- there is no reconciliation between them yet, and
   none is planned until GL auto-posting itself is decided (#4b.2).
   Each `BankAccount` gained its own `opening_balance_sgd` /
   `opening_balance_date`; every debit/credit line is a new
   `BankTransaction` row (own `BT-####` numbering via
   `numbering.py`), never a `JournalLine`.

31.2. **DECIDED by implementation.** Debit/credit follow the same
   convention as `JournalLine`: separate non-negative `debit_sgd`/
   `credit_sgd` columns, exactly one of which must be > 0 per line
   (money in vs. money out), rather than a signed single amount.

31.3. **DECIDED by implementation.** A `BankTransaction` is never
   hard-deleted -- a wrong entry is voided with a required reason
   (`void_reason`/`voided_at`), same pattern as `JobOrder.void_reason`.
   A voided line stays visible in the ledger (struck through) but is
   excluded from the running balance and reconciled-balance
   calculations.

31.4. **DECIDED by implementation.** "Bank Reconciliation" ticks off
   transactions as reconciled (individually, or as part of saving a
   dated reconciliation session) and permanently records each session
   (`BankReconciliation`: statement date, statement balance, the
   ledger balance as at that date, and the difference) -- this history
   is never overwritten, so a past reconciliation's own numbers don't
   change even if more transactions are added/voided later.

## 32. Sales GP costing: Quotation line cost -> Sales Invoice (raised 2026-09-12)

Requested as: "Sales Quotation Add Costing Open Field when choose
Non Product, Product Master per item to have costing field per line
... Purpose of Product cost per line eventually is to produce report
in accounting sales invoice listing detail with GP."

32.1. **DECIDED by implementation.** `Invoice` is deliberately
   single-line (see app/models/billing.py's own docstring: "A richer
   InvoiceLine breakdown is future work ... not a business-rule
   assumption") and is never raised directly from a Sales Quotation --
   only from a Contract's annual billing (BILL-001) or an Excess Usage
   record (SRV-008). Introducing a full multi-line Invoice breakdown to
   get a literal per-line GP would have meant redoing invoice
   generation, GST calculation, and every print/PDF template that
   assumes one amount -- a much bigger change than "add a cost field."
   Built the smaller, compatible version instead: `QuotationLine.cost_sgd`
   (open field, defaults from `Product.cost_sgd` when a product is
   picked) is summed across the quotation that converted into a
   CONTRACT_ANNUAL invoice's contract, and stored as that invoice's own
   `cost_sgd` at issue time (a point-in-time snapshot, not a live
   lookup -- see app/services/billing.py's `_cost_basis_for_contract`).
   GP is then revenue-per-invoice minus that cost, which is "sales
   invoice listing detail with GP" at invoice granularity rather than
   true line-item granularity.

32.2. **DECIDED by implementation.** An EXCESS_USAGE invoice's
   `cost_sgd` stays null (shown as $0 cost / 100% GP) -- excess support
   hours have no quotation or product to trace a cost from. This is an
   implementation default (there is no COGS concept for support labour
   anywhere in this system), not a claim that excess usage truly costs
   nothing; revisit if/when labour costing is ever modelled (see #7.2's
   still-open "what labour cost rate is used for project cost
   tracking").

## 33. Commission report: formula confirmed, rate is Dennis's to set (raised 2026-09-12)

Requested as: "calculation of commission based on Receipt Applied to
Sales Invoice with GP calculation per month" -- following on from
Commission Management being deferred wholesale at #6.

33.1. **DECIDED with Dennis.** Asked which of two common formulas this
   meant -- a percentage of GP, or a percentage of the raw receipt
   amount. **Dennis chose % of GP per invoice**, prorated by how much
   of that invoice a given receipt has actually settled (not the full
   invoice value if only partially paid), grouped by the month the
   receipt was received (`Payment.payment_date`), and credited to the
   invoice's own `Contract.sales_staff_id` -- an existing field, not a
   new assumption about how sales ownership works (#8.2 is otherwise
   still open). This only partially resolves #6: it answers 6.1 (how
   commission is calculated) and 6.2 (when it becomes payable -- on
   receipt, not invoicing) for the purposes of this report; 6.3
   (approval), 6.4 (clawback) and 6.5 (payout mechanism) remain open --
   this is a report showing what commission would be, not a payment
   workflow.

33.2. **DECIDED with Dennis, built.** The percentage itself was never
   given a number, and CLAUDE.md is explicit that a business rule like
   this is never invented -- so it's a plain admin-editable setting
   (`CommissionSettings.rate_percent`, one row per company, editable
   from the Commission report screen itself) rather than a hardcoded
   guess. Defaults to 0% (no commission calculated) until Dennis sets
   it.

## 34. Company/Individual - Product - License Type (raised 2026-09-12)

Requested as: "Company/Individual - Product - License Type (LOCAL) /
(RDP) / (WEB) - No of Licenses".

34.1. **DECIDED by implementation.** There is no direct
   Company/Individual <-> Product link anywhere in this system outside
   of `ContractProduct` (a Contract's product coverage) -- so license
   type and count were added there (`license_type`, `number_of_licenses`,
   both optional) rather than inventing a new, separate link. A
   licensed product a customer has is therefore tracked per contract
   that covers it, which matches how every other per-customer/per-
   product fact in this system already works (contract coverage, not a
   standalone customer-product master). Named `LicenseDeploymentType`
   in code to avoid colliding with the unrelated, pre-existing
   `app.models.licensing.LicenseType` (this system's own module-
   licensing tier: included/add-on/trial).

34.2. **Known limitation, not raised as a question:** PATCH
   `/contracts/{id}` (bulk-replacing a contract's product_ids) deletes
   and recreates every `ContractProduct` row, which would silently wipe
   any license_type/number_of_licenses already set. A separate PATCH
   `/contracts/{id}/products/{product_id}` endpoint exists specifically
   so editing license details doesn't require touching coverage at all;
   flagged here so a future edit to the coverage-replacement endpoint
   doesn't reintroduce data loss without noticing this interaction.

## 35. Service Record grammar-check (raised 2026-09-12)

Requested as: "Service record detail to have AI built in Grammar
Checking during key in."

35.1. **DECIDED with Dennis.** This would mean a live AI API call (a
   new external dependency, with real latency and per-call cost) every
   time a Service Record is being typed. Asked whether to use an AI
   API, rely on the browser's own native spellcheck, or hold the
   feature entirely. **Dennis chose browser-native only** -- no new
   dependency, no AI API integration. Revisit once the "AI Assistant"
   business area (already a candidate in CLAUDE.md's initial scope) is
   properly scoped, if a real grammar-checker is still wanted then.

35.2. **DECIDED by implementation.** Service Records had no free-text
   field at all before this (see app/models/service_records.py's
   original fields -- purely structured: minutes, completion status,
   after-hours flag). Added an optional `work_description` so there is
   something for the browser's spellcheck to actually check; existing
   records simply have it null.

## 36. Incident Module (raised 2026-09-11, settled and built 2026-09-12)

Moved from docs/planned-work.md #2 once its remaining open questions
were settled. Requested as: Support Staff log incoming calls/issues,
which get routed to Sales (a Quotation), Support (a Job Order),
Software Tasks, or "someone to return call" -- converting auto-creates
the real target record (confirmed 2026-09-11), not just an assignment.

36.1. **DECIDED with Dennis.** "Someone to return the call" is a
   status (`PENDING_CALLBACK`) plus an assignee on the Incident itself
   -- no separate reminder/task record. Whoever it's assigned to is
   expected to see it via the Incidents screen's own filters; there is
   no dedicated per-staff callback queue built here (Ops Dashboard's
   personal task tracker was the alternative considered and declined).

36.2. **DECIDED with Dennis.** The Outlook Add-in's "Convert to Job
   Order" button requires a valid contract for that customer first
   (confirmed 2026-09-11). When the sender's email doesn't resolve to a
   known Company/Individual, or that customer has no contract in
   ACTIVE/EXCEEDED status, it **falls back to creating a plain Incident
   instead of erroring** -- exactly what "Convert to Incident" would
   have done, with the reason surfaced back to the sender rather than
   silently swallowed (`IncidentFromEmailResult.fallback_reason`). The
   in-app "Convert to Job Order" action on an already-logged Incident
   uses the same ACTIVE/EXCEEDED validity check but surfaces a plain
   422 error instead (there's no "fall back to creating an Incident"
   case there, since the Incident already exists).

36.3. **DECIDED with Dennis.** The Outlook Add-in was designed
   alongside the in-app Incident screen rather than deferred, but
   **cannot be sideloaded or tested** without a real Microsoft 365
   tenant and an HTTPS host, neither of which exist in this
   environment -- see outlook-addin/README.md. It also does not use
   Azure AD / Office SSO as the original request's phrasing implied;
   it reuses this app's own existing login instead, which needed no
   Azure AD app registration to build against an environment that
   doesn't have one. Office SSO remains a valid, undone future
   enhancement (see the README's "known gaps").

36.4. **DECIDED by implementation.** Lives under the existing
   `service_operations` module (already labelled "Helpdesk / Service
   Operations (Job Orders)" in `scripts/seed_demo.py`'s MODULE_CATALOG)
   rather than a new module key -- an Incident is exactly the Helpdesk
   front door for that same area, and every Group Authority already
   granted for Job Orders carries over with no seed-data changes
   needed.

36.5. **DECIDED by implementation.** Converting an Incident to a
   Quotation creates a real draft Quotation with one placeholder line
   (description = the Incident's subject, price SGD 0) rather than no
   line at all, since Quotation already requires at least one line
   (existing rule, not new) and an Incident only ever carries a
   subject/description, never product/price detail for Sales to price
   properly afterward.

36.6. **Not yet built:** the "someone to return Call" outcome is
   exactly captured (36.1); the routing UI for "Sales decide Quote or
   directly go to Software Tasks" is the Incidents screen's own Convert
   buttons, not a separate decision workflow -- if Dennis wants
   something more structured than "staff picks a button" here, that's
   still open.

---

## 37. Mobile Web App for Support Staff (raised 2026-09-11, all settled 2026-09-12)

All 8 open questions from planned-work.md #1 were settled with Dennis
before building. Implementation is in `app/routers/mobile.py`,
`app/models/attachments.py`, `app/services/file_storage.py`, and
`frontend/src/pages/MobileApp.tsx`.

37.1. **DECIDED.** Auth model: same login credentials (existing
   User/Group Authority account), filtered to own assigned Job Orders
   only (OPEN/ASSIGNED status). Mobile app has its own login screen at
   `/mobile`, separate from the desktop layout.

37.2. **DECIDED.** Time in/out replaces manual minutes: `time_in` is
   captured on the service record when the staff "clocks in" on-site;
   `time_out` is captured when they finish. `raw_minutes` is auto-
   computed from elapsed time and `rounded_minutes` via the existing
   `round_up_to_nearest()` rounding logic.

37.3. **DECIDED.** "What is done" field: free-text `work_description`
   entered at time-out alongside completion status and after-hours flag.
   Supplements (does not replace) the existing service record fields.

37.4. **DECIDED.** Photo/video attachments: no limit on count or size
   (nginx set to 500MB). Stored as files on a Docker volume (`/app/
   uploads/`), not base64 inline. Camera-only capture (`capture=
   "environment"`) plus file chooser for gallery/multiple.

37.5. **DECIDED.** Signature: finger-drawn on-screen (canvas-based
   touch/mouse drawing), stored as a PNG data URI. Customer in-charge
   name is free-text (not tied to an existing Contact record).

37.6. **DECIDED.** Chop photo no-reuse enforcement: camera-only capture
   (no gallery re-pick for chop specifically), plus a Pillow-generated
   watermark overlay on each chop photo. The watermark includes the
   Service Record number as semi-transparent diagonal text plus a solid
   bottom strip with SR number and timestamp. This binds each chop
   image visually and permanently to one specific Service Record, making
   re-use immediately detectable.

37.7. **DECIDED.** Storage and retention: attachments use soft-delete
   (`is_deleted` flag, never physically removed), consistent with the
   project's never-permanently-delete audit-trail rules. Signoff records
   (signature + chop) have no delete mechanism at all -- once signed,
   the signoff is permanent and one-per-service-record (unique
   constraint on `service_record_id`).

37.8. **DECIDED.** Live connection assumed. No offline mode or local
   queue. All actions require a working network connection.

---

## How to use this document

- Do not start detailed schema or workflow design for an area until the
  decisions that affect it are resolved, or an explicit interim
  assumption is agreed with Dennis and recorded here.
- When a decision is made, update the relevant item's status (e.g. to
  **DECIDED — <summary>**, with a pointer to where the rule is documented
  in [business-requirements.md](business-requirements.md) or a future
  module-specific requirements document) rather than deleting it, so the
  decision history is preserved.
- New items should be added here as detailed requirements gathering
  uncovers further undecided rules — this list is a living document, not
  a one-time checklist.
