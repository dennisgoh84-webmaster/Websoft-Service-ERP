# Deploying for Staff Testing

A single-VPS Docker Compose deploy for all three parts of the Websoft
Service ERP ecosystem. Each part is independently deployable, but this
guide covers running everything on one server.

| # | Part | Repo | Container(s) | Exposed Port |
|---|------|------|-------------|-------------|
| 1 | **ERP (Client App)** — desktop + mobile web | this repo | `frontend` (nginx) → `backend` (FastAPI) → `db` (Postgres) | `:80` (HTTP_PORT) |
| 2 | **Mobile Web App** | this repo | Built into ERP frontend at `/mobile` | Same as ERP |
| 3 | **Customer Helpdesk Portal** | this repo | Built into ERP frontend at `/portal` | Same as ERP |
| 4 | **Central Command** | [websoft-central-command](https://github.com/dennisgoh84-webmaster/websoft-central-command) | `cc-frontend` (nginx) → `cc-backend` (FastAPI) → `cc-db` (Postgres) | `:8080` (CC_HTTP_PORT) |

---

## 0. Before you deploy

Run these from a clean checkout of the commit you intend to ship. Both
failures below are invisible in a running dev session and only surface
on the server.

```bash
# 1. The frontend image runs this -- if it fails, the build fails.
cd frontend && npm run build && cd ..

# 2. Migrations must apply to an EMPTY database, not just your dev one.
cd backend
createdb migration_check -O websoft_app
database_url="postgresql+psycopg://websoft_app:<pw>@localhost:5432/migration_check" \
  uv run alembic upgrade head
database_url="postgresql+psycopg://websoft_app:<pw>@localhost:5432/migration_check" \
  uv run python scripts/seed_demo.py
dropdb migration_check
cd ..
```

See [DEV_SETUP.md](DEV_SETUP.md#verifying-before-you-push-or-deploy) for
why `npx tsc --noEmit` does **not** substitute for step 1, and why a
fresh database catches enum bugs an incremental migration never will.

---

## 1. Get a VPS

Any small Linux VPS with Docker works — DigitalOcean, Linode, AWS
Lightsail, a Hetzner box, etc. 2 vCPU / 4 GB RAM is comfortable for
all 3 parts under a staff-testing load (1 vCPU / 2 GB is enough for
the ERP alone). Install Docker + the Compose plugin on it (each
provider's "Docker" marketplace image, or the
[official install script](https://docs.docker.com/engine/install/),
already includes both).

## 2. Get the code onto it

```bash
git clone https://github.com/dennisgoh84-webmaster/websoft-service-erp.git
cd websoft-service-erp
git checkout claude/webmaster-erp-setup-qjz74z   # or whichever branch you want to test
```

---

## Part 1, 2 & 3: ERP + Mobile Web App + Customer Helpdesk Portal

### 3a. Configure secrets

```bash
cp .env.example .env
```

Edit `.env` and fill in:
- `POSTGRES_PASSWORD` — any strong password, this VPS's own DB only.
- `JWT_SECRET_KEY` — generate with `openssl rand -hex 32`. Never reuse
  the `dev-only-secret` default in `backend/app/core/config.py`.
- `SMTP_*` — only if you want the "Email" button (Purchase Order,
  Sales Quotation/Invoice, Receipt/Payment Voucher, Statement of
  Accounts) to actually send, and for the Helpdesk Portal's login OTP
  and staff-issued invite/reset emails to go out. Leave blank and
  everything still works: Email/portal-OTP is simply skipped (fail-open,
  see customer-portal-design.md §4), and a portal invite/reset shows the
  temporary password on screen once instead of emailing it.
- `HTTP_PORT` — leave as `80` unless this box already has something
  else listening there.

### 4a. Bring it up

```bash
docker compose up -d --build
```

This builds the backend and frontend images, starts Postgres, runs
`alembic upgrade head` once via the one-shot `migrate` service, then
starts `backend` and `frontend`. Check it's healthy:

```bash
docker compose ps
docker compose logs -f migrate   # confirm migrations applied cleanly
```

Visit `http://<vps-ip>/` (or `http://<vps-ip>:<HTTP_PORT>` if you
changed it). You should see the login page.

The **Mobile Web App** is accessible at `http://<vps-ip>/mobile`, and
the **Customer Helpdesk Portal** at `http://<vps-ip>/portal` — neither
needs a separate deploy. Enable a customer contact's portal access from
their Company/Individual detail page → Contacts tab (needs PDPA consent
recorded first).

### 4b. Running the PHP backend instead (optional, not the default)

The backend has been converted from Python/FastAPI to PHP/Laravel
(`backend-php/`, complete 2026-09-15 — see
[docs/php-conversion-plan.md](docs/php-conversion-plan.md)). Both
backends are in `docker-compose.yml`; the PHP one sits behind a
`php` profile, so the command above still brings up the Python stack
and nothing else. **Switching production across is a deliberate
decision, not something the deploy does on its own.**

To run the PHP backend alongside the Python one:

```bash
docker compose --profile php up -d --build
```

Set `APP_KEY` in `.env` first (see the note there for how to generate
one). To point the frontend at it, change `proxy_pass` in
`frontend/nginx.conf` from `http://backend:8000` to
`http://backend-php:8000` and rebuild `frontend` — the PHP image
serves HTTP on the same port precisely so this is a one-line,
reversible change.

Two things to know before you do:

- **Only one of the two migration services may ever run against a
  given database.** Both manage the same schema — that is the point of
  the conversion — but Alembic and Laravel each keep their own
  bookkeeping, and running one over the other's database is not
  something either tool supports.
- The PHP image installs `libreoffice-writer`, like the Python one,
  for the "Email X" buttons' .docx → PDF step. Without it the Word
  downloads keep working while every Email button fails — a silent,
  one-sided failure that is easy to miss.

### 5a. Load ERP demo data

**Choose one:**

- **Demo data** (fastest way to get staff clicking around):
  ```bash
  docker compose exec backend uv run python scripts/seed_demo.py
  ```
  ⚠️ **This truncates every table and reseeds from scratch.** Fine for
  a first run on an empty database; **never** run it again once staff
  have entered real test data of their own, or it will wipe it. Gives
  you the demo logins (all password `demo1234`):

  | Email | Role |
  |---|---|
  | dennis@websoft.local | owner (also manages Module Control) |
  | nico@websoft.local | service_lead |
  | cherish@websoft.local | sales_manager |
  | weiling@websoft.local | support_engineer (mobile app user) |

- **Real staff accounts from day one**: skip seeding, sign in as
  whichever first user you create directly in Postgres (or ask me to
  add a one-off "create first owner" script), then use **Staff Master**
  (Company Setup → Staff Master, once logged in as owner) to add each
  tester with their own login and the right Group Authority.

---

## Part 3: Central Command

Central Command is deployed from its **own repository** —
[dennisgoh84-webmaster/websoft-central-command](https://github.com/dennisgoh84-webmaster/websoft-central-command)
— with its own Docker Compose stack (`cc-db`, `cc-backend`,
`cc-frontend` on `CC_HTTP_PORT`, default `8080`).

```bash
git clone https://github.com/dennisgoh84-webmaster/websoft-central-command
cd websoft-central-command
```

Full deployment steps (secrets, bring-up, login, backups) are in that
repo's `README.md`. It can run on the same VPS as this ERP — its
Postgres maps to host port 5433 to avoid clashing with the ERP database
on 5432.

---

## 6. Add HTTPS (recommended before sharing the link outside your own network)

Both compose stacks serve plain HTTP. For a real shared testing
URL, put a TLS-terminating proxy in front — simplest options:

- **Caddy**: a Caddyfile with two entries gets you automatic Let's
  Encrypt HTTPS for both:
  ```
  erp.your-domain.com {
      reverse_proxy localhost:80
  }
  cc.your-domain.com {
      reverse_proxy localhost:8080
  }
  ```
- **nginx + certbot** on the host, proxying to `127.0.0.1:80` and
  `127.0.0.1:8080`.
- A tunnel service (Cloudflare Tunnel, Tailscale Funnel) if you'd
  rather not open inbound ports on the VPS at all.

Ask me to wire up whichever of these you'd prefer, once you have
domains pointed at the VPS.

## 7. Updating after new pushes

```bash
# ERP (root of repo)
git pull
docker compose up -d --build

# Central Command (separate repo)
cd ../websoft-central-command
git pull
docker compose up -d --build
```

`migrate` re-runs on every `up` but Alembic is idempotent (it only
applies migrations not already recorded as run), so this is always
safe — it will never re-truncate or re-seed data.

**One-off after the GL-posting upgrade (2026-09-14):** a database that
already held invoices, bills, receipts or payments before this version
needs them posted to the General Ledger once. New documents post
automatically; existing ones don't until you run:

```bash
docker compose exec backend uv run python scripts/post_backlog.py --dry-run   # see what it would do
docker compose exec backend uv run python scripts/post_backlog.py             # do it
```

Safe to re-run — documents already posted are skipped. A fresh database
seeded with `seed_demo.py` does not need this.

## 8. Backups

Both Postgres databases use named volumes. Back them up regularly
(CLAUDE.md requires backup/recovery):

```bash
# ERP database
docker compose exec db pg_dump -U websoft_app websoft_service_erp | gzip > backup-erp-$(date +%F).sql.gz

# Central Command database (separate repo)
cd ../websoft-central-command
docker compose exec cc-db pg_dump -U cc_app central_command | gzip > backup-cc-$(date +%F).sql.gz
```

Restore into a fresh volume:
```bash
# ERP
gunzip -c backup-erp-*.sql.gz | docker compose exec -T db psql -U websoft_app websoft_service_erp

# Central Command (separate repo)
cd ../websoft-central-command
gunzip -c backup-cc-*.sql.gz | docker compose exec -T cc-db psql -U cc_app central_command
```

---

## Summary: What runs where

```
┌─────────────────────────────────────────────────────────────┐
│  VPS                                                         │
│                                                              │
│  ┌──────────────────── ERP Stack ──────────────────────┐     │
│  │  :80  nginx (frontend)                              │     │
│  │         ├── /           → React SPA (desktop)       │     │
│  │         ├── /mobile     → React SPA (mobile)        │     │
│  │         ├── /portal     → React SPA (customer portal)│     │
│  │         └── /api/*      → backend :8000             │     │
│  │  :8000  FastAPI backend                             │     │
│  │         (or backend-php :8000 — Laravel, `php`      │     │
│  │          profile, opt-in; see 4b)                   │     │
│  │  :5432  PostgreSQL (websoft_service_erp)             │     │
│  └─────────────────────────────────────────────────────┘     │
│                                                              │
│  ┌─────────── Central Command Stack ──────────────────┐     │
│  │  :8080  nginx (cc-frontend)                         │     │
│  │         ├── /           → React SPA                 │     │
│  │         └── /api/*      → cc-backend :8001          │     │
│  │  :8001  FastAPI backend                             │     │
│  │  :5433  PostgreSQL (central_command)                 │     │
│  └─────────────────────────────────────────────────────┘     │
│                                                              │
└──────────────────────────────────────────────────────────────┘
```
