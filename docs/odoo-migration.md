# Odoo Data Migration

Status: **import tooling built 2026-09-25** (`backend-php/app/Services/OdooMigration/`,
`php artisan odoo:import`). Originally recorded as
[planned-work.md #6](planned-work.md#6-odoo-migration-program----contacts-subscriptions-timesheets-sales-quotationsinvoicesreceipts-chart-of-accounts-raised-2026-09-12).

This covers how migrated Odoo records map onto this system, which decisions
govern it, and how to run it.

## Decisions (Dennis, 2026-09-25)

These answer the open questions planned-work #6 listed:

| # | Question | Decision |
|---|---|---|
| 1 | How the tool gets Odoo data | **Odoo's own CSV/Excel exports.** The tool never connects to Odoo. |
| 3 | Linking an Odoo ID to the new record | Built as a permanent `odoo_record_map` table (see below). |
| 4 | Historical vs operational | **Invoices and receipts are history only.** They never post to the General Ledger. The ledger is carried across by **one opening-balance journal voucher** from Odoo's Trial Balance at cut-over. |
| 5 | Document numbering | **Imported documents keep their Odoo number** (`INV/2025/00001`, `S00012`, `SUB/2025/0001`). This system's own counters carry on unchanged for new documents. Odoo timesheets have no number, so each one takes a number from this system's own Service Record counter. |
| 6 | Cut-over order | The order is set by dependencies. See **Order** below. |

Question 2 (field mapping) is answered below, one record type at a time.
Open-decisions #10.1 and #10.2 are still open: which history counts as
"important", and the module-by-module phasing order. The tool can import any
subset in any cut-over window.

## How the tool behaves

- **It does a dry run unless you pass `--commit`.** A dry run performs every
  lookup and every insert, then rolls the whole run back. Its report is
  therefore exactly what a commit would do.
- **A commit is all or nothing.** If any row fails, nothing from the file is
  written. Rows that are *skipped* on purpose (drafts, cancelled documents,
  credit notes, supplier payments) are reported but do not block the commit.
- **Re-runs are safe.** A record already in `odoo_record_map` is reported as
  `already_imported` and left as it is. The tool never overwrites a record
  that may have been edited here since it was imported. The normal loop is:
  dry run, fix the failed rows in the file, dry run again, commit.
- **Every run is recorded.** Dry runs, refused commits and successful commits
  each leave an `odoo_import_runs` row with the row-by-row report, plus an
  Event Log entry (`odoo_import_run`).
- **Rows are matched by Odoo's External ID.** Export from Odoo with **"I want
  to update data (import-compatible export)"** ticked. That adds the `id`
  column and lets references use `partner_id/id`. Columns are also accepted
  under their plain export labels (`Customer`, `Is a Company`, …).
- **Money is never recomputed.** Every amount (line subtotal, untaxed, tax,
  total, amount due) is Odoo's own figure. The GST rate recorded on a
  document is the rate Odoo actually charged, worked out from Odoo's own tax
  and untaxed amounts, so a document from the 7%/8% years keeps its rate.
- **Only SGD is imported.** A row whose `currency_id` is anything else fails.
  Converting historical foreign-currency figures at some rate would mean
  inventing numbers.

## Order

Run the files in this order. Each import resolves its references through
the ones before it.

1. `accounts`: Chart of Accounts
2. `opening_balances`: Trial Balance at cut-over (needs the accounts)
3. `contacts`: Contacts
4. `subscriptions`: Subscriptions (needs the contacts)
5. `quotations`, `invoices`, `receipts`: need the contacts; invoices link to
   a contract when `invoice_origin` names one
6. `timesheets`: need the contacts and the staff users

## Running it

```bash
cd backend-php
php artisan odoo:import contacts ~/odoo/res_partner.xlsx --company=WEBCOPT1            # dry run
php artisan odoo:import contacts ~/odoo/res_partner.xlsx --company=WEBCOPT1 --commit \
    --user=dennis@webmaster.com.sg
php artisan odoo:import opening_balances ~/odoo/trial_balance.csv --company=WEBCOPT1 \
    --as-at=2026-06-30 --commit
```

- `--company` takes the company code shown on Company Setup.
- `--user` records who ran the import in the Event Log. It is optional.
- `--show=problems` lists only the failed, skipped and warned rows.
- The command exits non-zero when a dry run finds failures or a commit is
  refused.

## Mapping, per record type

### `accounts`: Odoo Chart of Accounts → Account

The columns are `id`, `code`, `name`, and `account_type`. The account type
can be Odoo 16+'s technical value (`asset_receivable`) or its label
(`Receivable`). Odoo ≤15's `user_type_id` label is also accepted. Each type
maps onto one of the five types here (asset, liability, equity, revenue,
expense). `off_balance` has no equivalent here, so it fails.

If a code already exists in this company, for example from the seeded chart,
the Odoo account is **linked to it, not duplicated or renamed**. When the two
systems disagree on the account's type, the row gets a warning.

### `opening_balances`: Odoo Trial Balance → one posted journal voucher

The columns are `code`, or `account` (as in `1100 Trade Debtors`, where the
leading code is used). Amounts come either as `debit` + `credit` or as one
signed `balance` (positive = debit). Rows with a zero balance are ignored.

The whole file becomes **one** voucher dated `--as-at`. The voucher goes
through the ordinary Ledger rules, so the file must balance and the period
must be open. Otherwise nothing is posted. Each cut-over date can be
imported only once.

This voucher is how receivables, bank balances, retained earnings and every
other ledger balance arrive here. That is why migrated invoices and receipts
post nothing.

### `contacts`: Odoo Contacts → Company/Individual, or Contact

- **A partner with no parent** becomes a Company/Individual. It is a
  `company` if `is_company` is set, otherwise an `individual`.
- **A partner with a parent** (`parent_id`, "Related Company") becomes a
  **Contact** under its parent. The file can list parents and children in
  any order.

| Odoo | Here |
|---|---|
| `name` | name |
| `ref` | legacy customer code |
| `company_registry` | UEN |
| `vat` | GST registration no. |
| `email` / `phone` / `mobile` / `website` | billing email / phone / mobile / website |
| `street`, `street2`, `city`, `state_id`, `zip`, `country_id` | address |
| `category_id` | tags |
| `comment` | memo (HTML stripped) |
| `property_payment_term_id` | payment terms days (`30 Days` → 30, `Immediate Payment` → 0, anything else left blank with a warning) |
| `customer_rank` / `supplier_rank` | is customer / is supplier (> 0), when the export includes them |
| `active` | active |

**PDPA consent is never set by migration.** Odoo does not record it, and
consent is not something to assume.

### `subscriptions`: Odoo Subscriptions → Contract

The Odoo reference becomes the contract number. The `partner_id` becomes the
customer, and `start_date` / `end_date` carry across as the contract dates.
The contract value comes from `recurring_total` (or `amount_untaxed`). The
salesperson (`user_id`) is matched to a staff user; if there is no match it
is left blank with a warning. The status comes from `subscription_state` or
`stage_id`:

- draft / quotation → draft
- in progress / paused / to renew → active
- renewed → renewed
- churned / closed → expired

**Odoo has no contracted hours, so you add these columns to the export by
hand:**

| Column | Meaning |
|---|---|
| `contract_kind` | `service_support`, `annual` or `ad_hoc`. If blank, it is `service_support` when `contracted_hours` is filled in, otherwise `annual`. |
| `contracted_hours` | Hours for a service-support contract. The usual 10-hour minimum applies. |
| `consumed_hours` | Hours already used in Odoo. This becomes the contract's balance. |
| `hourly_rate` | Optional, as on any contract. |

If an open-ended Odoo subscription has no end date, the contract gets the
standard 12-month term and the row gets a warning. A migrated contract does
**not** issue its annual invoice. Its invoices arrive through the `invoices`
import.

### `quotations`: Odoo Sales Quotations → Quotation

The Odoo number is kept. The status maps as follows:

- `draft` → draft
- `sent` → sent
- `sale` / `done` → accepted
- `cancel` → rejected

Odoo writes a quotation with several lines as one row per line. The first
row carries the quotation itself. The rest carry only the `order_line/...`
columns and are read as more lines of the same quotation. Section and note
lines are left out.

An accepted quotation does **not** create a contract on import.

### `invoices`: Odoo Sales Invoices → Invoice (history)

Only posted customer invoices (`out_invoice`) are imported. The rest are
skipped:

- drafts and cancelled invoices
- credit notes (`out_refund`), because there is no credit-note document here
  yet

What Odoo had already been paid (`amount_total − amount_residual`) is stored
as `pre_migration_paid_sgd`. The invoice is therefore outstanding here by
exactly Odoo's amount due. A receipt recorded here after cut-over settles the
rest in the ordinary way, and the invoice's paid figure is Odoo's payments
plus the allocations made here. If `invoice_origin` names a migrated
contract, the invoice is linked to it.

**Nothing posts to the General Ledger.** No commission, stock or contract
side effect runs either.

### `receipts`: Odoo customer payments → Receipt Voucher (history)

Only posted inbound customer payments are imported. Supplier and outbound
payments are skipped. The Odoo journal is noted on the voucher. Each receipt
is recorded as fully applied before cut-over (`pre_migration_allocated_sgd`),
because what it settled is already reflected in each invoice's Odoo amount
due. It is therefore not offered again as unapplied customer credit. Any
customer credit that was genuinely unapplied in Odoo is carried in the
opening receivables balance.

A migrated receipt **cannot be entered into the bank book**. The Bank step
refuses it, because the bank balance arrives through the opening-balance
voucher and banking it again would count the money twice.

### `timesheets`: Odoo Timesheets → Service Record (history)

In this system, a Service Record always belongs to a Job Order. Odoo has no
Job Orders, so the import opens **one closed Job Order per customer +
project + task** (subject `Odoo: <project> / <task>`, numbered from this
system's counter). Later lines and later runs reuse that Job Order.

Each timesheet line arrives as an already-approved Service Record:

- The hours are Odoo's own figure, not re-rounded under SRV-007.
- The outcome is `not_hour_metered`. The line does **not** deduct from any
  contract, because a migrated contract's balance already arrives as its
  `consumed_hours`.

The employee must already be a staff user here, matched by full name, email
or username. A former employee needs an inactive user created first.

## What changed outside the importer

- `invoices.pre_migration_paid_sgd`: `AccountsReceivableService::recalculateInvoiceStatus()`
  adds it to the allocations. It is zero on every invoice raised here.
- `payments.pre_migration_allocated_sgd`: `Payment::allocatedSgd()` adds it
  to the allocations. It is zero on every receipt recorded here.
- `invoices.odoo_imported_at` / `payments.odoo_imported_at` mark migrated
  documents. `Posting::bankReceipt()` refuses a migrated receipt.

## Not covered (yet)

- Credit notes, supplier bills and supplier payments. They were not in the
  confirmed mapping.
- Products and stock. Also not in the confirmed mapping.
- An on-screen import page. The tool is a command for whoever runs the
  cut-over, with a report per run. A Maintenance screen could sit on the
  same `OdooImporter::run()` later.
