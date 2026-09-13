# Local Development Setup

This covers running the full Websoft Service ERP ecosystem on your own
machine for development and testing. The system has **three parts**:

| # | Part | Description | Default Port |
|---|------|-------------|--------------|
| 1 | **ERP (Client App)** | The main ERP system — desktop web + mobile web | Frontend `:5173`, Backend `:8000` |
| 2 | **Mobile Web App** | Runs inside the ERP frontend at `/mobile` | Same as ERP (`:5173`) |
| 3 | **Central Command** | Admin portal for managing all client ERP instances | Frontend `:5174`, Backend `:8001` |

For standing up a shared server so other staff can test it, see
[DEPLOY.md](DEPLOY.md) instead.

---

## Prerequisites

- Python 3.11+, [uv](https://docs.astral.sh/uv/)
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
createuser websoft_app --pwprompt   # password: websoft_dev_local (or your own — update backend/.env)
createdb websoft_service_erp -O websoft_app
```

### 1b. Backend (FastAPI)

```bash
cd backend
uv sync
uv run alembic upgrade head          # apply all migrations
uv run python scripts/seed_demo.py   # reset + load demo data (see script docstring)
uv run uvicorn app.main:app --reload --port 8000
```

API docs: http://127.0.0.1:8000/docs

Config is read from environment variables / a local `.env` file (see
`app/core/config.py`); defaults match the database setup above and are
for local development only — never use the default JWT secret in any
real deployment.

### 1c. Frontend (React + TypeScript)

```bash
cd frontend
npm install
npm run dev -- --port 5173
```

Open http://127.0.0.1:5173 — the dev server proxies `/api` to the
backend on port 8000 (see `vite.config.ts`).

### Demo logins

All password `demo1234`, after running `seed_demo.py`:

| Email | Role |
|---|---|
| dennis@websoft.local | owner (also manages Module Control) |
| nico@websoft.local | service_lead (Nico — SRV-004 excess-usage reviewer) |
| cherish@websoft.local | sales_manager (Cherish — SRV-011 backup reviewer) |
| weiling@websoft.local | support_engineer |

---

## Part 2: Mobile Web App

The mobile web app is **built into the ERP frontend** — no separate
server needed. Once Part 1 is running:

- Open http://127.0.0.1:5173/mobile on a phone-sized browser or mobile
  device.
- Login as a support engineer (e.g. `weiling@websoft.local` / `demo1234`).
- The mobile app shows the engineer's assigned Job Orders, Service
  Records, Time In/Out, photo attachments, and customer sign-off with
  signature pad.

**Note:** The mobile app uses the same backend API at
`/api/mobile/*` — no additional backend setup is required beyond Part 1.

---

## Part 3: Central Command (Admin Portal)

Central Command is a **completely separate application** with its own
database, backend, and frontend. It manages all client ERP instances:
licenses, concurrent login limits, advertisements, config pushes, and
version control.

### 3a. Database

```bash
createuser cc_app --pwprompt   # password: cc_dev_local (or your own — update central-command/backend/.env)
createdb central_command -O cc_app
```

### 3b. Backend (FastAPI)

```bash
cd central-command/backend
uv sync
uv run python seed.py                # create default admin user + tables
uv run uvicorn app.main:app --reload --port 8001
```

API docs: http://127.0.0.1:8001/docs

Config is in `app/core/config.py` — defaults match the database setup
above.

### 3c. Frontend (React + TypeScript)

```bash
cd central-command/frontend
npm install
npm run dev -- --port 5174
```

Open http://127.0.0.1:5174 — the dev server proxies `/api` to the CC
backend on port 8001 (see `vite.config.ts`).

### Central Command login

| Username | Password | Role |
|---|---|---|
| admin | Admin123 | super_admin |

---

## Quick Start — All 3 Parts at Once

Run each command in a separate terminal (or use `&` / tmux / screen):

```bash
# Terminal 1: ERP Backend
cd backend && uv run uvicorn app.main:app --reload --port 8000

# Terminal 2: ERP Frontend (includes Mobile at /mobile)
cd frontend && npm run dev -- --port 5173

# Terminal 3: Central Command Backend
cd central-command/backend && uv run uvicorn app.main:app --reload --port 8001

# Terminal 4: Central Command Frontend
cd central-command/frontend && npm run dev -- --port 5174
```

Then open:
- **ERP Desktop:** http://127.0.0.1:5173
- **Mobile Web App:** http://127.0.0.1:5173/mobile
- **Central Command:** http://127.0.0.1:5174

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
  attachment (converted from the same .docx used for its Word export —
  see `app/services/pdf_convert.py` and `app/services/document_email.py`,
  the shared helper every document type's Email button calls). It is
  unconfigured by default: until `backend/.env` carries real settings,
  the button fails with a clear "Email sending is not configured yet"
  error instead of pretending to send. Add to `backend/.env`:

  ```
  smtp_host=smtp.office365.com
  smtp_port=587
  smtp_username=...
  smtp_password=...
  smtp_from_email=...
  smtp_from_name=Web Master Consultancy
  ```

  One shared mailbox for the whole install, not per-company. Do not
  commit real credentials — `.env` is gitignored.
- **WhatsApp** opens a `wa.me` chat link pre-filled with a short message
  (no API/account needed) — you attach the PDF yourself in the chat.
  Needs a phone number set on the relevant Company/Individual record.

---

## Demo video

`frontend/record_demo.cjs` uses Playwright to script and record a
walkthrough of the confirmed workflow (login → contract hours →
approve a Service Record that exhausts the contract → Nico's
excess-usage review → resulting invoice). Run `node record_demo.cjs`
from `frontend/` with both servers running; re-run `seed_demo.py` first
for a clean, repeatable recording.

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
