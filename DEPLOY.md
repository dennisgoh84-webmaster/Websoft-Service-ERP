# Deploying for Staff Testing

A single-VPS Docker Compose deploy for all three parts of the Websoft
Service ERP ecosystem. Each part is independently deployable, but this
guide covers running everything on one server.

| # | Part | Container(s) | Exposed Port |
|---|------|-------------|-------------|
| 1 | **ERP (Client App)** — desktop + mobile web | `frontend` (nginx) → `backend` (FastAPI) → `db` (Postgres) | `:80` (HTTP_PORT) |
| 2 | **Mobile Web App** | Built into ERP frontend at `/mobile` | Same as ERP |
| 3 | **Central Command** | `cc-frontend` (nginx) → `cc-backend` (FastAPI) → `cc-db` (Postgres) | `:8080` (CC_HTTP_PORT) |

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

## Part 1 & 2: ERP + Mobile Web App

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
  Accounts) to actually send. Leave blank to leave Email disabled;
  everything else (Print, Word export, WhatsApp) works regardless.
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

The **Mobile Web App** is accessible at `http://<vps-ip>/mobile` — no
separate deploy needed.

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

### 3b. Configure secrets

```bash
cp central-command/.env.example central-command/.env
```

Edit `central-command/.env` and fill in:
- `CC_POSTGRES_PASSWORD` — any strong password for the CC database.
- `CC_JWT_SECRET_KEY` — generate with `openssl rand -hex 32`.
- `CC_HTTP_PORT` — default `8080`. Change if that port is taken.

### 4b. Bring it up

```bash
cd central-command
docker compose up -d --build
```

This starts the CC database (Postgres on internal port 5432, mapped to
host port 5433 to avoid conflicts), backend (port 8001), and frontend
(nginx on `CC_HTTP_PORT`).

```bash
docker compose ps
docker compose logs cc-backend   # confirm seed ran
```

Visit `http://<vps-ip>:8080` (or whichever `CC_HTTP_PORT` you set).

### Central Command login

| Username | Password | Role |
|---|---|---|
| admin | Admin123 | super_admin |

The default admin user is created automatically by the seed script that
runs on first startup.

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

# Central Command
cd central-command
docker compose up -d --build
```

`migrate` re-runs on every `up` but Alembic is idempotent (it only
applies migrations not already recorded as run), so this is always
safe — it will never re-truncate or re-seed data.

## 8. Backups

Both Postgres databases use named volumes. Back them up regularly
(CLAUDE.md requires backup/recovery):

```bash
# ERP database
docker compose exec db pg_dump -U websoft_app websoft_service_erp | gzip > backup-erp-$(date +%F).sql.gz

# Central Command database
cd central-command
docker compose exec cc-db pg_dump -U cc_app central_command | gzip > backup-cc-$(date +%F).sql.gz
```

Restore into a fresh volume:
```bash
# ERP
gunzip -c backup-erp-*.sql.gz | docker compose exec -T db psql -U websoft_app websoft_service_erp

# Central Command
cd central-command
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
│  │         └── /api/*      → backend :8000             │     │
│  │  :8000  FastAPI backend                             │     │
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
