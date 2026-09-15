# Websoft Service ERP Solution

A custom ERP / business management system for **Webmaster Consultancy
Pte Ltd** (Singapore), built to replace Odoo module by module.

| | |
|---|---|
| **Company** | Webmaster Consultancy Pte Ltd |
| **Country / Currency / Timezone** | Singapore · SGD · Asia/Singapore |
| **Backend** | PHP 8.4 / Laravel 11 (`backend-php/`) |
| **Frontend** | React 19 + TypeScript (`frontend/`) |
| **Database** | PostgreSQL 16 |
| **Tests** | 802 passing (`cd backend-php && php artisan test`) |

---

## What this is

One application with four faces, three of them served from the same
frontend build:

| # | Part | Where | Who uses it |
|---|---|---|---|
| 1 | **ERP (desktop web)** | `/` | Office staff |
| 2 | **Mobile Web App** | `/mobile` | Engineers on site — own job orders, time in/out, work photos, customer sign-off |
| 3 | **Customer Helpdesk Portal** | `/portal` | Customers — their own contracts, job orders, invoices; raise an incident |
| 4 | **Central Command** | [separate repo](https://github.com/dennisgoh84-webmaster/websoft-central-command) | Managing all client ERP instances |

The Portal is a genuinely separate auth realm: portal users live in
their own table, never in staff `users`, and a staff token is refused
by every portal endpoint and vice versa.

---

## Modules

**Built and working**

Core / Administration · Customer Management · Sales (Quotations,
Product/Service Catalog) · Service Contracts · Helpdesk / Service
Operations (Job Orders) · Service Records · Billing · Accounts
Receivable · Accounts Payable · Purchasing · Finance / Accounting
(GL, periods, year-end) · Stock Master + Goods Receive / Transfer /
Return / Issue Notes + Stock Adjustment · Stock Operation Reports ·
Operations Reports · Accounting Reports · Commission (report, rate,
payouts) · Software Tasks · Event Logs · Bank Book · Ops Dashboard ·
Reporting / Management Dashboard

**Not started** — CRM · Projects · Hardware Management · Integrations
(incl. Odoo migration) · AI Assistant

Every module is independently switchable per company through **Module
Control**, and access within a module is **VIEW / EDIT / FULL** per
group (**Group Authority**).

---

## Rules the system enforces

These are confirmed business rules, not conventions — each is pinned
by tests:

- **Nothing financial is ever deleted.** Corrections are reversals:
  an invoice is written off, a GL voucher is reversed by a mirror
  entry, a commission is clawed back by a negative payout. The mistake
  and its correction both stay on record.
- **Every material action is audited** — who, when, what changed, and
  a reason where one is required.
- **Stock can never go negative and is valued at weighted average.**
  An issue that exceeds what a warehouse holds refuses the whole
  document rather than issuing part of it. Only a receipt re-weights
  the average; a transfer carries cost across unchanged.
- **Money is never floating point.** All arithmetic goes through a
  decimal money type.
- **A business rule is never assumed.** Where a requirement has not
  been confirmed, the code reports "not available" rather than
  inventing a figure — the commission rate starts at zero, an invoice
  with no known cost reports no cost rather than 100% margin.

Singapore specifics anticipated throughout: GST, PDPA (consent gating,
archival), InvoiceNow / Peppol, and financial audit trails.

---

## Getting started

- **Run it locally** → [DEV_SETUP.md](DEV_SETUP.md)
- **Deploy it to a server** → [DEPLOY.md](DEPLOY.md)
- **Install or upgrade a test server in one command** →
  [deploy/README.md](deploy/README.md)
- **See what the system actually does** →
  [docs/walkthrough/](docs/walkthrough/index.html) — a 46-step guided
  path with screenshots of every screen. Open `index.html` in a browser.

```bash
# Local, once Postgres is running (see DEV_SETUP.md for the full setup)
cd backend-php && php artisan migrate --seed && php artisan serve
cd frontend && npm install && npm run dev
```

---

## Two backends, one API

`backend/` (Python / FastAPI) was the original implementation.
`backend-php/` (PHP 8.4 / Laravel 11) is a **complete conversion** of
it — same PostgreSQL schema, same JSON API — done module by module and
finished 2026-09-15. Every Python router and every export route now
has a PHP equivalent.

**They are not equivalent going forward.** Work since the conversion
has landed in `backend-php/` only, including product-based Sales
Invoicing, Management Reporting, Commission Payouts and the Sales
Dashboard. `backend/` is kept as the running system of record until
the cutover, which is a deliberate decision, not a deploy side effect:
it is one line in `frontend/nginx.conf`, described in
[DEPLOY.md §4b](DEPLOY.md).

> **Two bugs found in `backend/` during the conversion, still unfixed
> there:** its commission service passes positional arguments to a
> keyword-only function, so Commission Payouts cannot run and an AR
> write-off will fail once a non-zero commission rate is set; and the
> Job Orders report tests for a `resolved` status this system has
> never had, so a voided job order reads as overdue. Both are correct
> in `backend-php/`. See
> [docs/php-conversion-plan.md](docs/php-conversion-plan.md).

---

## Documentation

| Document | What it covers |
|---|---|
| [docs/walkthrough/](docs/walkthrough/index.html) | Guided walkthrough of every module, with screenshots — open `index.html` in a browser |
| [docs/business-requirements.md](docs/business-requirements.md) | Confirmed business rules (SRV / BILL / AR / PUR / INV / HW series) |
| [docs/system-architecture.md](docs/system-architecture.md) | Architecture, Module Control, Group Authority |
| [docs/backlog.md](docs/backlog.md) | Everything pending, checkable, linking into the detail docs |
| [docs/open-business-decisions.md](docs/open-business-decisions.md) | Questions still waiting on a decision |
| [docs/planned-work.md](docs/planned-work.md) | Confirmed future work, recorded but not yet designed |
| [docs/php-conversion-plan.md](docs/php-conversion-plan.md) | The Python → PHP conversion: approach, findings, what changed |
| [docs/gl-posting-design.md](docs/gl-posting-design.md) | Sub-ledger → GL posting and the Bank step |
| [docs/customer-portal-design.md](docs/customer-portal-design.md) | Customer Helpdesk Portal design (PORTAL-001..006) |
| [docs/ui-guidelines.md](docs/ui-guidelines.md) | Screen label conventions, Export and Print patterns |
| [docs/module-map.md](docs/module-map.md), [docs/workflows.md](docs/workflows.md) | Supporting planning docs |
| [CLAUDE.md](CLAUDE.md) | Project instructions and full development history |

---

## Development rules

The test suite gates `main` in both directions — a red suite is never
pushed, and green finished work is not left sitting on a branch. Run
before every push:

```bash
cd backend-php && php artisan test && ./vendor/bin/pint --test
cd frontend && npm run build
```

Migrations for every schema change. Business logic in services, not
controllers. Validation on both sides. No unnecessary dependencies.
