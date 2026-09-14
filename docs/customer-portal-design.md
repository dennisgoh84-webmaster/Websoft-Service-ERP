# Customer Helpdesk Portal

Status: **BUILT — confirmed with Dennis 2026-09-14, built and verified
the same day.** Decisions here are recorded as PORTAL-001..PORTAL-004 in
[business-requirements.md](business-requirements.md). All 7 items in the
§9 test plan below passed, including the §9.4 token boundary tests
(portal token refused on every staff endpoint and vice versa, a portal
user requesting another customer's job-order id gets 404 never 403).

## 1. What it is

A login for the people at a customer — the **Contact** records already
attached to each Company/Individual — to see their own contracts and
hour balance, job orders, service records and incidents, and to raise a
new Incident that lands in the existing Helpdesk queue. Read-only on
everything else; nothing financial in this version.

It reuses what exists rather than building a second system: the same
backend, the same `Incident` model and Helpdesk workflow, the same OTP
and password machinery as staff login, and the same frontend app —
mounted at `/portal` the way the mobile app is mounted at `/mobile`.

## 2. Decisions (2026-09-14)

| # | Decision | Chosen |
|---|---|---|
| PORTAL-001 | Who logs in | **Each Contact person individually.** One credential per person, enabled per contact by staff. Gives a real audit trail of who at the customer raised or viewed what. |
| PORTAL-002 | First-version scope | **View + raise Incidents.** Contracts and hour balance, job orders, service records, incidents; create an Incident. No invoices, statements or payments. |
| PORTAL-003 | Identity separation | Pragmatic default: portal users live in **their own table** (`portal_users`), never in staff `users`, and carry a **purpose-tagged token** that staff endpoints reject. A portal credential can never reach a staff screen. |
| PORTAL-004 | PDPA gate | Pragmatic default: access can be enabled only for a contact whose Company/Individual has `pdpa_consent_given` and is not archived. Archiving the customer (task #72) disables every portal user under it. |

## 3. Data model

`app/models/portal.py`:

```
PortalUser
  id                    UUID pk
  company_id            FK companies        (tenant)
  contact_id            FK contacts, UNIQUE (one login per contact)
  email                 String(255)         copied from Contact at enable; UNIQUE per company
  hashed_password       String
  is_active             bool  default True
  must_change_password  bool  default True  (invite flow)
  last_login_at         DateTime | None
  created_by_user_id    FK users            (the staff member who enabled it)
  created_at
```

`IncidentSource` gains `PORTAL = "portal"`.

`Incident` gains `raised_by_portal_user_id` (FK, nullable) so a
portal-raised incident records the person, not just the customer.

Login OTPs reuse `LoginOtp` (`app/models/core.py`) with a new
`portal_user_id` nullable FK alongside the existing `user_id` — one of
the two is set. This avoids a second OTP table and a second mailer path.

## 4. Auth

Reused from `app/services/auth.py`: `hash_password`, `verify_password`,
`validate_password_complexity` (same 8-char alphanumeric policy as
staff), `create_purpose_token` / `decode_purpose_token`.

Flow (mirrors staff, deliberately):

1. `POST /api/portal/auth/login` — email + password → if valid, send OTP
   by email via `mailer.py`, return `{otp_required: true}`.
2. `POST /api/portal/auth/verify-otp` — returns a **purpose token**,
   `purpose = "portal"`, carrying `portal_user_id`.
3. If `must_change_password`, the client is sent to change it first
   (`POST /api/portal/auth/change-password`).
4. `POST /api/portal/auth/forgot-password` — self-service reset, same
   email+OTP shape as the staff one.

`app/core/deps.py` gains `get_current_portal_user`: decodes with
`expected_purpose="portal"`, loads `PortalUser`, refuses if inactive or
if its customer is archived. The existing `get_current_user` continues
to decode staff tokens only — a portal token presented to a staff
endpoint fails there. **This is the whole security boundary; it is
tested explicitly (§9.4).**

Rate limiting on login: reuse whatever the staff login does; if nothing,
add a per-email attempt counter on `PortalUser` (`failed_attempts`,
`locked_until`) — 5 failures, 15-minute lock.

## 5. Enabling access — staff side

On the Company/Individual detail page, Contacts tab, each contact gets
**Enable portal access** / **Disable** / **Reset password**.

`POST /api/company-individuals/{id}/contacts/{contact_id}/portal-access`

- Requires module `company_individual_management` at EDIT.
- Refuses unless the contact has an email, the customer has
  `pdpa_consent_given`, and the customer is not archived (PORTAL-004).
- Creates `PortalUser` with a generated temporary password,
  `must_change_password = True`, and emails an invite (portal URL +
  temporary password) via `document_email.py`'s mailer. If SMTP is not
  configured, the temporary password is shown to the staff member once
  on screen instead — same behaviour the staff-creation flow has.
- Writes an Event Log entry: who enabled access for whom.

Disable sets `is_active = False`; the token dependency then refuses.
Archiving a customer (existing task #72 flow) calls the same disable for
every portal user under it.

## 6. Portal endpoints

All under `/api/portal`, all depending on `get_current_portal_user`, and
**every query filtered by `portal_user.contact.customer_id`** — a portal
user can never address another customer's id, because ids are never
accepted as input; the customer comes from the token.

| Method | Path | Returns |
|---|---|---|
| GET | `/me` | contact name, customer name, must_change_password |
| GET | `/contracts` | the customer's contracts with contracted / consumed / remaining hours and expiry |
| GET | `/job-orders` | their job orders: number, subject, type, status, assigned engineer name, due date |
| GET | `/job-orders/{id}` | plus its service records (date, engineer, minutes rounded, completion) |
| GET | `/service-records` | flat list, newest first |
| GET | `/incidents` | their incidents with status |
| POST | `/incidents` | `{subject, description}` → creates `Incident` with `customer_id` from the token, `source = PORTAL`, `sender_name/email/phone` from the contact, `raised_by_portal_user_id` set |

Deliberately **not** exposed: anything with money (invoice, quotation,
rate, cost), internal notes, deduction minutes vs raw (customers see
the rounded, approved figure only), other contacts, staff names beyond
the assigned engineer.

Each endpoint reuses the existing service-layer queries with a customer
filter — no duplicated business logic.

## 7. Frontend

Mounted like the mobile app: `<Route path="/portal/*" element={<PortalApp />} />`
outside `Layout`, so it has no staff sidebar.

- `src/lib/PortalAuthContext.tsx` — separate context and a separate
  token key (`websoft_portal_token`) so staff and portal sessions never
  collide in one browser.
- `src/portal/` pages: Login (email/password → OTP → change password),
  Home (contract hour balance front and centre, open incidents, recent
  job orders), Contracts, Job Orders (+ detail with service records),
  Incidents (+ New Incident form).
- Company logo and name from the public branding endpoint the staff
  login already uses.
- Phone-first layout; it will mostly be opened from a phone.

## 8. Module key and nav

New module key `customer_portal`, registered like the others
(`app/routers/*` `MODULE` constant, Module Control, Group Authority).
It gates the **staff** actions (enable/disable/reset) — not the portal
itself, which has its own auth. Central Command can therefore licence
the portal per client like any other module.

## 9. Test plan

1. Enable access for a contact with consent → invite; without consent →
   refused with a PDPA message; contact without email → refused.
2. Login → OTP → forced password change → home shows the right
   customer's hours.
3. Raise an incident from the portal → appears in staff Incidents with
   `source = portal` and the contact's details; staff can convert it as
   usual.
4. **Boundary:** a portal token on `GET /api/company-individuals` →
   401. A staff token on `GET /api/portal/me` → 401. A portal user
   requesting another customer's job-order id → 404, never 403 (no
   information leak).
5. Disable access → next request refused. Archive the customer → same.
6. Wrong password ×5 → locked 15 minutes.
7. `npm run build` and empty-DB migration pass.

## 10. Out of scope (recorded)

- Invoices / statements / online payment in the portal — a later
  version, once BILL/AR posting (gl-posting-design.md) is live.
- Customer-side attachments on incidents.
- Multiple customers per contact (a person who works for two clients).
- SSO / social login.
