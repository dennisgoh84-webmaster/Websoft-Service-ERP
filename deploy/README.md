# Test server: install and upgrade

One command to stand up a Websoft Service ERP test server, and one to
upgrade it. Both run the **PHP/Laravel backend** (`backend-php/`),
which is where all current work lives.

> **Not yet run anywhere.** These scripts and the Docker image were
> written without a Docker daemon available, so nothing here has been
> executed end to end. The application itself is well tested (802
> passing), but *this packaging* is unproven — expect to fix something
> on the first build, and read [What can go wrong](#what-can-go-wrong)
> before you start.

---

## What you need

- A Linux server (2 GB RAM is enough; LibreOffice makes the image
  chunky, so allow ~5 GB disk)
- Docker Engine with Compose v2 — `docker compose version` must work
- Port 80 free, or set `HTTP_PORT` to something else

---

## Install

```bash
git clone https://github.com/dennisgoh84-webmaster/websoft-service-erp.git
cd websoft-service-erp
./deploy/install.sh
```

That generates the secrets into `.env`, builds the images, starts
Postgres, applies migrations, seeds demo data, and starts the app. It
prints the URLs and the demo login when it finishes.

**Or, without git** — download and unpack a package (see
[Making a package](#making-a-package)), then run `./deploy/install.sh`
inside it.

`install.sh` refuses to run if `.env` already exists, so it can never
quietly re-key or re-seed a server someone is using.

---

## Upgrade

```bash
cd websoft-service-erp
./deploy/upgrade.sh
```

In order: **backs up the database first**, pulls the latest `main`,
builds the new images while the old ones keep serving, applies
migrations, restarts.

The backup comes first because migrations are the one step a restart
cannot undo. If the dump comes out empty the script stops before
anything touches the schema. Backups land in `backups/`, last 10 kept,
and the rollback commands are printed at the end.

Uploaded files and the database are in named Docker volumes. Nothing
in either script deletes them — neither ever runs `down -v`.

```bash
./deploy/upgrade.sh --no-pull    # rebuild the checkout you already have
```

---

## Day to day

```bash
C="docker compose -f deploy/docker-compose.php.yml"

$C ps                         # what is running
$C logs -f backend-php        # application log
$C restart backend-php        # restart just the app
$C exec db psql -U websoft_app -d websoft_service_erp   # database shell
$C down                       # stop everything (keeps all data)
```

---

## What can go wrong

**The build fails on `libreoffice-writer`.** It is a large package and
a slow mirror can time out. Re-run `./deploy/install.sh` — Docker
resumes from its cached layers. It is not optional: without it the
Word downloads keep working while every "Email X" button fails with
"PDF conversion failed", which is a silent, one-sided failure that is
easy to miss.

**Port 80 is taken.** `HTTP_PORT=8080 ./deploy/install.sh`, or edit
`HTTP_PORT` in `.env` and re-run `$C up -d`.

**`docker: permission denied`.** Add yourself to the docker group:
`sudo usermod -aG docker $USER`, then log out and back in.

**Migrations fail.** Nothing is half-applied — Laravel wraps each
migration in a transaction. Read `$C logs migrate-php`, fix, and
re-run `./deploy/upgrade.sh --no-pull`.

**You need the old Python backend instead.** This stack does not run
it. Use the repo's own `docker-compose.yml` — see
[DEPLOY.md](../DEPLOY.md). Note the two backends are no longer
equivalent: Sales Invoicing, Management Reporting, Commission Payouts
and the Sales Dashboard exist only in `backend-php/`.

---

## Making a package

For a server without git access, or to pin exactly what you shipped:

```bash
./deploy/make-package.sh
```

Writes `dist/websoft-service-erp-<date>-<commit>.tar.gz` plus a
`.sha256` next to it. Move it across and unpack:

```bash
scp dist/websoft-service-erp-*.tar.gz you@test-server:~/
ssh you@test-server
sha256sum -c websoft-service-erp-*.tar.gz.sha256
tar xzf websoft-service-erp-*.tar.gz
cd websoft-service-erp-*/
./deploy/install.sh
```

The package excludes `.git`, `node_modules`, `vendor`, and any local
`.env` — the images build their own dependencies, and secrets are
generated on the target server, never carried in a tarball.

Upgrading from a package means unpacking the new one beside the old
and running `./deploy/upgrade.sh --no-pull` in it, with `.env` and
`backups/` copied across first:

```bash
cp ../websoft-service-erp-<old>/.env .
cp -r ../websoft-service-erp-<old>/backups . 2>/dev/null || true
./deploy/upgrade.sh --no-pull
```

---

## Security note

This recipe is for a **test server**: plain HTTP, seeded demo data, and
a published demo password (`dennis@websoft.example` / `demo1234`).
Change that password as soon as the server is reachable by anyone else,
and put HTTPS in front before it holds anything real —
[DEPLOY.md section 6](../DEPLOY.md).

`.env` is written mode 600 and holds `APP_KEY`, which encrypts stored
mailbox passwords. Keep it: without it those cannot be read back.
