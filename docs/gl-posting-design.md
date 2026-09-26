# Sub-ledger → General Ledger posting, and the Bank step

Status: **BUILT — confirmed with Dennis 2026-09-14, built and verified
the same day.** Decisions here are recorded as ACC-001..ACC-004 in
[business-requirements.md](business-requirements.md).

## 1. The gap this closes

As of commit `8ac396b`, **no sub-ledger document posts to the General
Ledger.** Sales Invoices, Supplier Bills, Receipt Vouchers and Payment
Vouchers all update their own balances (invoice outstanding, bill paid,
etc.) but never create a `JournalEntry`. The only callers of
`ledger.post_entry` are the manual Journal Voucher screen and Year-End
Closing. The dashboard's "GL Trial Balance: Balanced" is true only
because nothing has been posted.

Receipts and payments also never write to the **bank book**
(`bank_transactions`), even though `BankAccount`, `BankTransaction` and
`BankReconciliation` exist and `BankAccount.gl_account_id` is already
seeded to `1000 Cash at bank`.

The ledger was built to receive these: `VoucherType` already has
`RECEIPT`, `PAYMENT`, `SALES_INVOICE`, `PURCHASE_INVOICE`; `JournalEntry`
has `source_type` / `source_id` for linking back to the originating
document, and `reverses_entry_id` for proper reversals; and the period
lock matrix already declares `GL`/`UNGL` for all four document types
plus `BANK`/`UNBANK` for receipts and payments. None of it is wired.

## 2. Decisions (2026-09-14)

| # | Decision | Chosen |
|---|---|---|
| ACC-001 | Scope | **All four documents post to GL** (invoice, bill, receipt, payment), and receipts/payments write to the bank book. Posting receipts alone would drive the AR control account negative, since nothing had debited it. |
| ACC-002 | Bank step | **Explicit "Bank" action, reversible with "Unbank"** — matching the `BANK`/`UNBANK` operations already in the lock matrix. Finance confirms the money moved as a step separate from recording the voucher. |
| ACC-003 | GL step | Pragmatic default, called out here rather than assumed: **GL posts automatically** when the document reaches its accounting event (invoice issued — BILL-005 says revenue is recognised on invoice; bill approved; receipt/payment saved). `UNGL` exists for corrections. Dennis may change this to an explicit "Post" action later without schema change. |
| ACC-004 | Un-post | `UNGL` **creates a reversing entry** (`reverses_entry_id`). Nothing is ever deleted — CLAUDE.md forbids destroying financial records. `UNBANK` **voids** the bank transaction (`is_voided`, `void_reason`), for the same reason. |

## 3. Account map

All accounts already exist in the seeded chart (`scripts/seed_demo.py`).
The map lives in one place — `app/services/posting.py` — so a chart
change is a one-line change.

| Role | Code | Name | Source |
|---|---|---|---|
| AR control | 1100 | Accounts receivable | fixed |
| AP control | 2000 | Accounts payable | fixed |
| GST output | 2100 | GST output tax (collected on sales) | fixed |
| GST input | 2110 | GST input tax (paid on purchases) | fixed |
| Bank | *per account* | `BankAccount.gl_account_id`, falling back to `1000 Cash at bank` | per bank account |
| Revenue | 4000 | Service contract revenue | `Invoice.invoice_type = contract_annual` |
| Revenue | 4010 | Excess usage revenue | `Invoice.invoice_type = excess_usage` |
| Revenue | 4020 / 4030 | Project revenue / Hardware sales | **no invoice type issues against these yet** — mapped now so they work the day one is added |
| Expense | 5000 | Cost of services | **default** for a supplier bill (see §4.2) |

## 4. Posting rules

Amounts are SGD. Every entry balances by construction; `post_entry`
still enforces it.

### 4.1 Sales Invoice — on issue

```
Dr 1100 Accounts receivable      total_amount_sgd
    Cr 4000/4010 revenue             amount_sgd        (net, by invoice_type)
    Cr 2100 GST output tax           gst_amount_sgd
```
`voucher_type = SALES_INVOICE`, `entry_date = issued_at.date()`,
`source_type = "invoice"`, `narration = "Sales invoice {number} — {customer}"`.

### 4.2 Supplier Bill — on reaching `approved`

```
Dr {expense account}             amount_sgd        (net)
Dr 2110 GST input tax            gst_amount_sgd
    Cr 2000 Accounts payable         total_amount_sgd
```
`voucher_type = PURCHASE_INVOICE`, `entry_date = invoice_date`,
`source_type = "supplier_invoice"`.

A bill in `awaiting_match` or `exception` does **not** post — an
unresolved mismatch must not sit in AP. It posts when PUR-003's
auto-approval (or a manual resolution) moves it to `approved`.

**Schema addition:** `SupplierInvoice.expense_account_id` (nullable FK
→ `accounts`). When null, the posting uses `5000 Cost of services`.
The bill form gets an optional "Expense account" dropdown limited to
`EXPENSE` accounts. Pragmatic default; Dennis can decide later whether
bills should require it.

### 4.3 Receipt Voucher — on save

```
Dr {bank GL}                     amount_sgd
    Cr 1100 Accounts receivable      amount_sgd
```
`voucher_type = RECEIPT`, `entry_date = payment_date`,
`source_type = "payment"` (the receipt model is `Payment` in
`app/models/payments.py`).

**Schema addition:** `Payment.bank_account_id` (FK → `bank_accounts`,
**required**). The Receipt Voucher form gets a "Bank account" dropdown,
defaulting to the company's first active account.

### 4.4 Payment Voucher — on save

```
Dr 2000 Accounts payable         amount_sgd
    Cr {bank GL}                     amount_sgd
```
`voucher_type = PAYMENT`, `entry_date = payment_date`,
`source_type = "supplier_payment"`.

**Schema addition:** `SupplierPayment.bank_account_id` (FK, required).

### 4.5 The Bank step (ACC-002) — receipts and payments only

`POST /api/ar/payments/{id}/bank` and `POST /api/ap/payments/{id}/bank`:

- Guard: `require_period_allows(..., RECEIPT_VOUCHER|PAYMENT_VOUCHER, BANK)`.
- Creates one `BankTransaction` on the voucher's `bank_account_id`:
  receipt → `debit_sgd = amount`, payment → `credit_sgd = amount`;
  `transaction_date = payment_date`, `reference = voucher reference`,
  `description = "RV {number} — {customer}"`, `created_by_user_id = actor`.
- Links it: **schema addition** `BankTransaction.source_type` /
  `source_id`, mirroring `JournalEntry`. Refuses a second bank step
  while a non-voided transaction exists for the source.

`.../unbank`: guard `UNBANK`; voids the transaction with a required
reason. A **reconciled** transaction cannot be unbanked — reverse the
reconciliation first. Both actions write Event Logs.

### 4.6 UNGL — all four documents

`POST /api/.../{id}/ungl` (one per document type): guard `UNGL`; creates a
reversing `JournalEntry` with `reverses_entry_id`, posted immediately,
narration "Reversal of {original voucher}". Refuses if already reversed.
Re-posting after an UNGL creates a fresh entry (new `source` link is
allowed because the prior one is marked reversed).

### 4.7 Idempotency and audit

- Before posting, `posting.py` looks up an existing non-reversed
  `JournalEntry` with the same `(source_type, source_id)` and refuses
  to double-post. DB-level: partial unique index on
  `(source_type, source_id) WHERE status != 'reversed'`.
- Every post, un-post, bank and unbank records an Event Log entry
  (actor, document, entry/transaction id).
- `posted_by_user_id` = the actor of the triggering action.

### 4.8 Out of scope for this iteration (recorded, not built)

- ~~**Write-off (AR-002) posting**~~ — **built 2026-09-26:** Dr 6700
  Bad debts written off (must be an Expense account) / Cr 1100 AR, a
  journal voucher dated the write-off day (`Posting::postWriteOff`).
- **Stock movements** — confirmed 2026-09-26 not to post; Finance makes
  a month-end Journal Voucher instead.
- **Credit notes** — no model exists yet (BILL-003 is a rule without a
  document). Out of scope.
- **Project / hardware invoice types** — accounts mapped, no issuing
  path yet.
- **Bank charges / FX** — single-currency SGD only; `BankAccount.currency_code`
  is stored but not used in posting.

## 5. Migration

One Alembic revision, hand-written (autogenerate has produced broken
drops before — see DEV_SETUP.md):

- `payments.bank_account_id` FK, NOT NULL — **backfill** existing rows
  to the company's first bank account before adding the constraint.
- `supplier_payments.bank_account_id` — same.
- `supplier_invoices.expense_account_id` FK, nullable.
- `bank_transactions.source_type` (String 50), `source_id` (UUID), nullable.
- Partial unique index on `journal_entries (source_type, source_id)`
  where `status <> 'reversed'`.
- **Data migration for existing documents:** a one-off management
  command `scripts/post_backlog.py` that posts every existing issued
  invoice, approved bill, receipt and payment in date order — run once
  on each database after upgrade, **not** inside the migration, so it
  can be re-run and inspected. Bank step is *not* back-filled; finance
  confirms those by hand.

Must pass the DEV_SETUP.md "migrations from an empty database" check.

## 6. Endpoints

| Method | Path | Guard |
|---|---|---|
| POST | `/api/ar/invoices/{id}/ungl` | UNGL |
| POST | `/api/ap/bills/{id}/ungl` | UNGL |
| POST | `/api/ar/payments/{id}/bank` · `/unbank` · `/ungl` | BANK / UNBANK / UNGL |
| POST | `/api/ap/payments/{id}/bank` · `/unbank` · `/ungl` | BANK / UNBANK / UNGL |
| GET | `/api/ledger/entries?source_type=&source_id=` | read |

Auto-posting hooks into the existing create/approve paths in
`accounts_receivable.py` and `payables.py` — the routers do not change
shape.

## 7. Frontend

- Receipt Voucher and Payment Voucher forms: **Bank account** dropdown.
- Supplier bill form: optional **Expense account** dropdown.
- Each voucher row: a **GL** chip (Posted / Reversed) linking to the
  entry, and for RV/PV a **Bank** chip (Banked / Not banked / Voided)
  with Bank / Unbank buttons.
- Bank Account detail page: transactions created by the bank step show
  their source document as a link.
- Dashboard "GL Trial Balance" tile keeps working — it now means
  something.

## 8. Test plan

1. Fresh DB → migrate → seed → run `post_backlog.py` → Trial Balance
   balances; AR control = Σ outstanding invoices; AP control = Σ unpaid
   approved bills.
2. Issue an invoice: entry appears; AR up by total; revenue by net; GST
   output by GST.
3. Bill in `exception` → no entry. Resolve to `approved` → entry appears.
4. Receipt: entry (Dr bank / Cr AR). Bank step → bank transaction. Unbank
   with reason → voided. Unbank a reconciled one → refused.
5. Close a period → GL / BANK / UNGL / UNBANK on a document inside it
   all refused with the period message.
6. UNGL an invoice → reversing entry; Trial Balance still balances;
   re-post allowed.
7. Post the same invoice twice → refused (idempotency).
8. `npm run build` and empty-DB migration both pass (the two deploy
   checks).
