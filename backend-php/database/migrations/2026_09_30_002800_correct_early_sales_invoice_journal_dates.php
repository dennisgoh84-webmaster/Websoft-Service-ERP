<?php

use App\Services\Audit;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Sales invoice vouchers dated a day early (Dennis, 2026-09-26: "yes
 * have to settle, correct them").
 *
 * A Sales Invoice's GL voucher takes its date from the invoice's issue
 * time. While the database session ran in UTC, that time came back in
 * UTC, so an invoice issued between midnight and 08:00 Singapore time
 * was posted on the previous day -- and at a month end, into the
 * previous month. Those vouchers are moved to the Singapore date of
 * their invoice, each change recorded in Event Logs against the
 * voucher.
 *
 * Only a voucher whose date is exactly the invoice's UTC date, and not
 * its Singapore date, is touched: every correctly dated voucher, every
 * manual voucher and every reversal (which carries no source invoice)
 * is left alone. Nothing else about the voucher changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $rows = DB::select(
                "SELECT je.id, je.voucher_number, je.company_id, je.entry_date::text AS old_date,
                        (i.issued_at AT TIME ZONE 'Asia/Singapore')::date::text AS new_date, i.invoice_number
                   FROM journal_entries je
                   JOIN invoices i ON i.id = je.source_id
                  WHERE je.source_type = 'invoice'
                    AND je.entry_date = (i.issued_at AT TIME ZONE 'UTC')::date
                    AND je.entry_date <> (i.issued_at AT TIME ZONE 'Asia/Singapore')::date"
            );

            foreach ($rows as $row) {
                DB::table('journal_entries')->where('id', $row->id)->update(['entry_date' => $row->new_date]);
                Audit::record(
                    entityType: 'journal_entry',
                    entityId: $row->id,
                    action: 'entry_date_corrected',
                    actorUserId: null,
                    actorName: 'System (migration 2026_09_30_002800)',
                    companyId: $row->company_id,
                    details: "{$row->voucher_number} ({$row->invoice_number}) was dated {$row->old_date}, a day early because of the "
                        .'old UTC database clock; moved to the invoice\'s Singapore date '.$row->new_date,
                    oldValue: ['entry_date' => $row->old_date],
                    newValue: ['entry_date' => $row->new_date],
                );
            }
        });
    }

    public function down(): void
    {
        // Intentionally empty: moving the vouchers back would only
        // reinstate the wrong dates. Each change is in Event Logs.
    }
};
