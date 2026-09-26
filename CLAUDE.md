# Websoft Service ERP Solution

## Project Overview

| Key | Value |
|---|---|
| Project Name | Websoft Service ERP Solution |
| Repository | [github.com/dennisgoh84-webmaster/Websoft-Service-ERP](https://github.com/dennisgoh84-webmaster/Websoft-Service-ERP) |
| Company | Webmaster Consultancy Pte Ltd |
| Country | Singapore |
| Currency | SGD |
| Timezone | Asia/Singapore |
| Database | PostgreSQL |

## Purpose

A custom ERP / business management system replacing Odoo for Webmaster
Consultancy, built module by module and run in parallel with Odoo until
each module is cut over. The application is `backend-php/` (PHP 8.4 /
Laravel 11 + PostgreSQL) and `frontend/` (React + TypeScript), plus the
Mobile App and the Customer Helpdesk Portal served from the same
frontend. See [DEV_SETUP.md](DEV_SETUP.md) to run it.

## What is built

Every module below is switched per company under **Module Control** and
granted per group (VIEW / EDIT / FULL) under **Group Authority**.

- **Sales** — Prospect / Leads with Prospect Activities (pipeline New →
  Qualified → Proposal → Negotiation → Won / Lost; accepting a quotation
  wins the prospect; activities are voided, never deleted), Quotations
  (Sales Manager approval, accept → contract), Product Catalog, Sales
  Dashboard. Roles: Sales Manager, Sales Supervisor, Sales Staff.
- **Company / Individual** — customers and suppliers in one file, with
  Contacts, Branches, Relationships, PDPA consent, a customer credit
  limit (going over it warns on quotations, Sales Invoices and Job
  Orders, never blocks), and per-party PO and credit note approval limits (all separate
  settings on the file).
- **Service Operations** — Service Contracts (hours, renewal, project
  milestones), Job Orders, Service Records (approval, contract-hour
  deduction, excess usage), Incidents (Helpdesk, Outlook / Gmail
  add-ins, and the **Email Inbox**, where the server reads the helpdesk
  mailbox over IMAP with no HTTPS needed), Software Tasks, Ops Dashboard, Mobile App.
- **Accounts** — Billing / Invoices, Accounts Receivable (receipts;
  write-offs by the owner or Finance, no amount limit, posted to 6700 Bad debts written off), Accounts Payable (purchase
  orders, 2-way matching, payment vouchers), General Ledger (automatic
  posting, journal vouchers, trial balance), Bank Book, GST and Account
  Period (lock matrix, Year-End Closing, **GST Calculation** — the saved
  Form 5 every GST report reads — and **Submit to IRAS**, which records
  who and when and locks the month — **Revise** reopens it for a
  corrected return, keeping the submitted one; supplier bills carry a
  purchase tax code and GST is worked out from it, as on sales), Commission Management, Accounting and
  Operations Reports.
- **Stock** — Stock Master, Goods Receive / Transfer / Return / Issue
  Notes, Stock Adjustment (weighted average cost; stock never negative;
  movements never post to the GL — Finance journals it at month end).
- **Maintenance** — Company Setup, Staff Master, Module Control, Group
  Authority, Document Control, Setup Lists, Announcements, System Email,
  Data Migration (ODOO / ZSOFT), AI Assistant (a paid add-on, hidden
  everywhere unless switched on), Event Logs.
- **Self-test** — one command (`./selftest/run.sh`) walks every screen
  on a desktop and a phone and keys in the main forms, checking what
  the server stored; nightly on the test server with a pass/fail email
  ([docs/self-test.md](docs/self-test.md)).

Dropped from scope on 2026-09-26: Projects and Hardware Management as
modules (PROJECT-type contracts stay part of Service Contracts), and the
separate Purchasing, Inventory and Integrations keys (covered by
Accounts Payable, the Stock keys and Data Migration).

"Ticket" / "Timesheet" are called "Job Order" / "Service Record"
throughout, at Dennis's request.

## Approved Architecture Decisions

The following architecture decisions have been approved for the initial
Websoft Service ERP Solution project. These decisions must not be changed without
explaining the reason first (see Development Rules below).

1. **Backend:** PHP 8.4 / Laravel 11 (`backend-php/`). **Changed
   2026-09-14** from Python + FastAPI for a team/hosting constraint,
   not a technical problem with FastAPI; converted module by module
   with the same PostgreSQL schema and the same JSON API contract, so
   the frontend was unaffected; **conversion complete and the Python
   backend retired and removed from the tree 2026-09-15**, at Dennis's
   instruction, once the PHP stack was running on the test server.
   `backend-php/` is the only backend. See
   [docs/php-conversion-plan.md](docs/php-conversion-plan.md) for the
   conversion's findings.
2. **Frontend:** React + TypeScript
3. **Database:** PostgreSQL
4. **Initial deployment:** Cloud/VPS deployment, with the architecture kept
   portable to AWS, Azure, or other infrastructure later.
5. **Odoo replacement strategy:** Phased, module-by-module replacement with
   a parallel-run period. A big-bang migration will not be used.
6. **Odoo historical data:** Important historical data will eventually be
   migrated into Websoft Service ERP Solution. Older data may be archived rather than
   fully operational.
7. **Multi-company:** The architecture should support multiple
   companies/entities in the future. The initial implementation is for
   Webmaster Consultancy Pte Ltd only.
8. **Singapore requirements:** The architecture must anticipate:
   - GST
   - InvoiceNow / Peppol
   - PDPA
   - Financial audit trails
   - Role-based access control
   - Data backup and recovery

## Working with Dennis

- **Every question to Dennis is a pick-list, never free text** —
  whenever a decision is his, ask it with the multiple-choice question
  tool (`AskUserQuestion`): 2–4 short options, the recommended one
  first and marked "(Recommended)", each with a one-line description
  of what it means; he can still type "Other". Several open decisions
  go in one call (up to 4 questions). Do not end a reply with questions
  he has to answer by retyping. Set by Dennis 2026-09-26: "don't keep
  making me repeat".
- When he answers, build it, record it where the rule lives
  (business-requirements.md / open-business-decisions.md), and don't
  ask the same thing again.

## Development Rules

- Use a modular and maintainable architecture.
- Use PostgreSQL as the primary database.
- All important financial and operational transactions must have audit trails.
- Never permanently delete important business or financial records.
  Use soft-delete, archival, reversal or VOID with a reason instead.
- Database changes must use migrations. Never modify production data
  directly — a correction to existing data is a migration, and each
  record it changes is written to Event Logs.
- Authentication and role-based permissions are required.
- Validate data on both frontend and backend.
- Write automated tests for important business logic.
- Do not introduce unnecessary dependencies.
- Keep business logic separate from the user interface.
- Document major architectural decisions.
- Do not change the approved architecture without explaining the reason first.
- Never assume a business rule when requirements have not been provided.
  A default taken meanwhile is named in a code comment and recorded in
  [docs/open-business-decisions.md](docs/open-business-decisions.md).
- **Times are Singapore time, end to end — check this before building
  anything that stores or shows a date or time.** The app
  (`APP_TIMEZONE`) and the PostgreSQL session (`config/database.php`
  `timezone`, which follows `APP_TIMEZONE`) run in the same zone, so:
  - Write the current moment with `now()` / `Carbon::now()`. Never
    `Carbon::now('UTC')`, `now('UTC')` or `->utc()` — a guard test
    (`TimeZoneRuleTest`) fails the suite on any of them in `app/`.
  - New time columns are `timestampTz` (`timestamp with time zone`);
    a date with no time of day is a `date` column.
  - The frontend formats with `lib/format.ts` (`formatDate`,
    `formatDateTime`, `formatTime`, `sgDateIso`, `todayIso`), never raw
    `toLocaleString()`, `toISOString().slice(0, 10)` or string slicing.
  Until 2026-09-26 the session ran in UTC while the app ran in
  Singapore time, so every time the app wrote was stored eight hours
  ahead; migration `2026_09_30_002500` corrected the stored rows.
- **Every date a person keys in uses `components/DateInput.tsx`** —
  never a bare `<input type="date">` or a text box. It reads and takes
  DD/MM/YYYY, fills in the slashes as digits are typed (a phone's number
  pad has no "/"), accepts a two-digit year, and its calendar opens on
  a phone. A form that records a document dated by the user (a bill, a
  voucher, a stock note) offers the date, defaulting to today, rather
  than silently using today.
- **Test every new or changed field by keying it in, on a desktop and
  on a phone-sized screen** (Playwright with a phone device profile):
  type into it, pick from its picker, submit, and check what was saved.
  A screen is not done until that has passed. That test is a key-in
  flow in the self-test (`selftest/runner/flows/`, docs/self-test.md),
  so it keeps being run; a new screen is swept automatically.
- **Company / Individual ID and name are FULL CAPITALS**, tidied of
  stray spaces, whichever way they arrive (screen, paste, import,
  add-in) — enforced on the model (`CompanyIndividual::caps`).
- **GST reports read saved GST Calculations, never live documents.**
  A month's figures are produced once its period is locked, kept with
  the documents behind them, and recalculated only as a new version.
- The test suite gates `main`, in both directions. Run the full suite
  (`cd backend-php && php artisan test`), `./vendor/bin/pint --test`,
  the frontend build (`cd frontend && npm run build`) and the self-test
  (`./selftest/run.sh`) before every push to `main` --
  `./selftest/pre-push.sh` runs all four in order (and is the git
  pre-push hook with `git config core.hooksPath .githooks`):
  - **Red — never push.** A failing suite is a blocker, never something
    to note in the commit message and push anyway.
  - **Green — push.** Finished, verified work goes to `main`; it is not
    left sitting on a branch or in a worktree waiting for a later batch.
  Confirmed 2026-09-14, when development moved to working on `main`
  directly and the branch-review buffer went away.

## Documentation

- [docs/business-requirements.md](docs/business-requirements.md) — confirmed business rules (SRV, BILL, AR, PUR, INV, SALES, GST series)
- [docs/system-architecture.md](docs/system-architecture.md) — system architecture
- [docs/module-map.md](docs/module-map.md), [docs/workflows.md](docs/workflows.md), [docs/open-business-decisions.md](docs/open-business-decisions.md) — supporting planning docs
- [docs/planned-work.md](docs/planned-work.md) — confirmed future work, described in enough detail to record, not yet designed or built
- [docs/gl-posting-design.md](docs/gl-posting-design.md), [docs/customer-portal-design.md](docs/customer-portal-design.md) — designs for sub-ledger → GL posting + Bank step, and the Customer Helpdesk Portal, decided **and built** 2026-09-14
- [docs/backlog.md](docs/backlog.md) — short, checkable summary of everything pending, linking into the detail docs above
- [docs/walkthrough/index.html](docs/walkthrough/index.html) — a 47-step guided walkthrough of every built module (Operations → Stock → Accounts → Maintenance), with screenshots captured from the running application. Regenerate the screenshots by running the app and re-capturing; they are not auto-built.
- [docs/ui-guidelines.md](docs/ui-guidelines.md) — screen label conventions and the Export (CSV/Excel) / Print (PDF/Word) pattern every screen follows
- [docs/outlook-addin.md](docs/outlook-addin.md) — the Outlook Add-in and its Gmail twin (Log as Incident / Convert to Job Order from an email): served at `/outlook-addin/` and `/gmail-addon/`, Maintenance → Email Add-ins for the filled-in files, the Gmail add-on's one-time connect code (`/connect-addin`), how to switch each on
- [docs/self-test.md](docs/self-test.md) — the self-test program: every screen on a desktop and a phone, the key-in flows, `./selftest/run.sh`, the before-push gate and the nightly run with its pass/fail email
- [docs/data-migration.md](docs/data-migration.md) — Maintenance → Data Migration (ODOO / ZSOFT): decisions, the screens, modules in run order, Field Gap sign-off, roll back
- [docs/php-conversion-plan.md](docs/php-conversion-plan.md) — the backend Python→PHP language conversion (complete; Python retired 2026-09-15): reason, approach, stack, findings
- [docs/build-history.md](docs/build-history.md) — the module-by-module record of how everything was built and what was found along the way (formerly this file's Status section)
- [DEV_SETUP.md](DEV_SETUP.md) — how to run the application locally

## Status

Built and in use on the test server; being extended module by module,
with Odoo still running alongside until each module is cut over. What
is pending — including every decision still waiting on Dennis — is in
[docs/backlog.md](docs/backlog.md) and
[docs/open-business-decisions.md](docs/open-business-decisions.md). The
full record of how each module was built, and what was found and fixed
along the way, is in [docs/build-history.md](docs/build-history.md).
