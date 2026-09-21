# Local Development Setup

This covers running the full Websoft Service ERP ecosystem on your own
machine for development and testing. The system has **four parts**:

| # | Part | Repo | Default Port |
|---|------|------|--------------|
| 1 | **ERP (Client App)** — desktop web + mobile web | this repo | Frontend `:5173`, Backend `:8000` |
| 2 | **Mobile Web App** — runs inside the ERP frontend at `/mobile` | this repo | Same as ERP (`:5173`) |
| 3 | **Customer Helpdesk Portal** — runs inside the ERP frontend at `/portal` | this repo | Same as ERP (`:5173`) |
| 4 | **Central Command** — admin portal for managing all client ERP instances | [websoft-central-command](https://github.com/dennisgoh84-webmaster/websoft-central-command) | Frontend `:5174`, Backend `:8001` |

For standing up a shared server so other staff can test it, see
[DEPLOY.md](DEPLOY.md) instead.

---

## Prerequisites

- PHP 8.4 with the `pdo_pgsql`, `gd`, `zip`, `intl`, `bcmath` extensions, and [Composer](https://getcomposer.org/)
- Node.js 20+
- PostgreSQL 16 (local install or any reachable instance)
- LibreOffice Writer, headless-capable (`apt install libreoffice-writer` on
  Debian/Ubuntu) — only needed for the "Email" button (see Email /
  WhatsApp section below), which converts the generated .docx to PDF via
  `soffice --headless --convert-to pdf`. `libreoffice-core`/`-common`
  alone is NOT enough — without the `-writer` package the conversion
  fails with "source file could not be loaded". Everything else (Print,
  Word export, WhatsApp) works without this.

---

## Part 1: ERP (Client App) — Desktop + Mobile

### 1a. Database

```bash
createuser websoft_app --pwprompt   # password: websoft_dev_local (or your own -- update backend-php/.env)
createdb websoft_service_erp -O websoft_app
```

### 1b. Backend (PHP / Laravel)

```bash
cd backend-php
cp .env.example .env                 # then set DB_* to the database above
composer install
php artisan key:generate
php artisan migrate --seed           # apply all migrations + load the demo data
php artisan serve --no-reload        # http://127.0.0.1:8000
```

`--no-reload` matters here, not just style: `php artisan serve` silently
ignores `PHP_CLI_SERVER_WORKERS` (set to 4 in `.env.example`) unless
`--no-reload` is passed -- without it, Laravel keeps a single worker so
it can restart the server on every `.env` change, and every request
(reports especially, being the slowest) queues behind whichever one is
already running. `--no-reload` trades that auto-restart for real
concurrency, which is what a shared dev/test server needs.

`php artisan migrate:fresh --seed` resets the database and reseeds it.
The seeder creates one company (Webmaster, with its letterhead and
logo), the module catalog, the Owner/Admin group, Dennis, the Chart of
Accounts, a bank account, one sample customer, and the announcements.

### 1c. Frontend (React + TypeScript)

```bash
cd frontend
npm install
npm run dev -- --port 5173
```

Open http://127.0.0.1:5173 — the dev server proxies `/api` to the
backend on port 8000 (see `vite.config.ts`).

### Demo logins

After seeding: `dennis@websoft.example` / `demo1234` (owner). Add other
staff from **Staff Master** once signed in. Under `npm run dev` the
Login page prefills these; a production build never does.

---

## Part 2: Mobile Web App

The mobile web app is **built into the ERP frontend** — no separate
server needed. Once Part 1 is running:

- Open http://127.0.0.1:5173/mobile on a phone-sized browser or mobile
  device.
- Sign in as a staff member who has Job Orders assigned (the seeded owner works; assign a Job Order to them first).
- The mobile app shows the engineer's assigned Job Orders, Service
  Records, Time In/Out, photo attachments, and customer sign-off with
  signature pad.

**Note:** The mobile app uses the same backend API at
`/api/mobile/*` — no additional backend setup is required beyond Part 1.

---

## Part 3: Customer Helpdesk Portal

Also **built into the ERP frontend** — no separate server needed. Once
Part 1 is running:

- Open http://127.0.0.1:5173/portal.
- A customer signs in with the email + temporary password staff issued
  them (Company/Individual detail page → Contacts tab → **Enable portal
  access**) — first sign-in forces a password change, then (if SMTP is
  configured) a 6-digit email code.
- Shows the signed-in contact's own contract hour balance, job orders +
  service records, and incidents, and lets them raise a new incident.
  Deliberately nothing about money (invoices, quotations, rates).

**Note:** Uses its own token (`websoft_portal_token`, separate from the
staff session) at `/api/portal/*` — see
[customer-portal-design.md](docs/customer-portal-design.md). No
additional backend setup is required beyond Part 1.

---

## Part 4: Central Command (Admin Portal)

Central Command is a **completely separate application** with its own
repository, database, backend, and frontend. It manages all client ERP
instances: licenses, concurrent login limits, advertisements, config
pushes, and version control.

**It lives in its own repository** —
[dennisgoh84-webmaster/websoft-central-command](https://github.com/dennisgoh84-webmaster/websoft-central-command).
Clone it next to this one and follow the setup instructions in that
repo's `README.md`:

```bash
git clone https://github.com/dennisgoh84-webmaster/websoft-central-command
```

Central Command talks to this ERP by writing **directly into each
client's PostgreSQL database**. The tables it writes to are a schema
contract this repo must honour — see
[docs/central-command-schema-contract.md](docs/central-command-schema-contract.md)
before changing `module_controls` or `announcements`.

---

## Quick Start — This Repo

Run each command in a separate terminal (or use `&` / tmux / screen):

```bash
# Terminal 1: ERP Backend
cd backend-php && php artisan serve --no-reload

# Terminal 2: ERP Frontend (includes Mobile at /mobile)
cd frontend && npm run dev -- --port 5173
```

Then open:
- **ERP Desktop:** http://127.0.0.1:5173
- **Mobile Web App:** http://127.0.0.1:5173/mobile
- **Customer Helpdesk Portal:** http://127.0.0.1:5173/portal

Central Command runs from its
[own repository](https://github.com/dennisgoh84-webmaster/websoft-central-command)
on ports 8001/5174 — see that repo's `README.md`.

---

## Email / WhatsApp on documents

Purchase Order, Service Records, Sales Quotation, Sales Invoice, Receipt
Voucher, Payment Voucher, and Statement of Accounts (on the Sales
Invoice page) can all Email or WhatsApp themselves to the relevant
Company/Individual record — the customer on the document, or the
supplier for a Purchase Order/Payment Voucher (a supplier is just a
Company/Individual flagged "Is Supplier", not a separate file — see
docs/open-business-decisions.md #23).

- **Email** sends for real over SMTP, with the document as a PDF
  attachment converted from the same .docx used for its Word export
  (`App\Services\PdfConvert`, `App\Services\DocumentEmail`). There
  are two mailboxes, deliberately separate, neither falling back to the
  other (`App\Services\Mailer`):

  - **Company mailbox** -- Company Setup → *Outbound email*, in the
    app. What every document "Email" button sends from, so the mail
    comes from the company's own domain and passes SPF/DKIM. Until it
    is filled in the button fails with a clear "Email is not configured
    for <company>" error instead of pretending to send. Has a "Send
    test email" button.
  - **System mailbox** -- `SMTP_*` in `backend-php/.env` (templated in
    `backend-php/.env.example`): login one-time codes, password resets
    and portal invites, which run before any company is chosen.

  `SMTP_USE_TLS=true` means a plain connection upgraded with STARTTLS
  (port 587), and the send fails if the server will not upgrade. Set it
  to `false` only for a server that genuinely has no TLS. Implicit
  TLS-on-connect (SMTPS, port 465) is not supported.

  > [docs/planned-work.md #8c](docs/planned-work.md) records that the
  > system mailbox should eventually be owned in Central Command and
  > pushed down rather than hand-edited in `.env`.
- **AI Assistant** needs an Anthropic API key: set it under Maintenance
  → AI Assistant (stored encrypted), or as `ANTHROPIC_API_KEY` in
  `backend-php/.env` as the bootstrap fallback. Nothing is sent to the
  provider until a company has the `ai_assistant` module enabled under
  Module Control and someone presses "Ask the assistant". Tests never
  call the provider (`AiClient::fake()`). `ANTHROPIC_BASE_URL` can
  point the SDK at a proxy or a local stand-in.
- **WhatsApp** opens a `wa.me` chat link pre-filled with a short message
  (no API/account needed) — you attach the PDF yourself in the chat.
  Needs a phone number set on the relevant Company/Individual record.

---

## Verifying before you push or deploy

Two checks catch things a normal dev session does not. Both are worth
running before a deploy.

### 1. The frontend build -- `npm run build`, never `tsc --noEmit`

```bash
cd frontend && npm run build
```

⚠️ **`npx tsc --noEmit` proves nothing in this repo.** The root
`tsconfig.json` is a solution file: `"files": []` plus references to
`tsconfig.app.json` / `tsconfig.node.json`. Invoking `tsc` against it
directly type-checks **zero files** and exits 0 no matter how broken
the code is. `npm run build` runs `tsc -b`, which follows the project
references and checks `src/` for real -- and it is what the frontend
Docker image runs, so a failure here is a failed deploy.

### 2. Migrations from an *empty* database

`php artisan migrate` against your existing dev database only replays
the migrations added since you last ran it. A fresh server applies
**every** migration to an empty database, which is a different code
path -- and the only one that catches a migration that disagrees with
the models or the seeder.

```bash
createdb migration_check -O websoft_app
DB_DATABASE=migration_check php artisan migrate:fresh --seed
dropdb migration_check
```

The test suite does this on every run (`RefreshDatabase`), so a green
`php artisan test` is the same proof.

---

## Demo video

`frontend/record_demo.cjs` uses Playwright to script and record a
walkthrough of the confirmed workflow (login → contract hours →
approve a Service Record that exhausts the contract → Nico's
excess-usage review → resulting invoice). Run `node record_demo.cjs`
from `frontend/` with both servers running; run
`php artisan migrate:fresh --seed` first for a clean, repeatable recording.

---

## Scope and open decisions

This build intentionally does not cover every module in
[docs/module-map.md](docs/module-map.md) — only the Service Operations
core slice, plus Module Control and the dashboard. Several pragmatic
implementation defaults were made where a business decision is still
open or deferred in
[docs/open-business-decisions.md](docs/open-business-decisions.md) (e.g.
item 9.1, who approves Service Records — deferred); these are called out
in code comments (see `app/services/service_records.py`) rather than
silently assumed, and should be revisited once decided.
