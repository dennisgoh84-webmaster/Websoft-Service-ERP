<?php

namespace App\Services;

use App\Models\DocumentNumberFormat;
use App\Models\DocumentSequence;
use Illuminate\Support\Carbon;

/**
 * Document numbering. Mirrors backend/app/services/numbering.py --
 * see that file's docstring for the full rationale. The counter row
 * is locked for the duration of the transaction (`lockForUpdate()`,
 * the Eloquent equivalent of SQLAlchemy's `.with_for_update()`), so
 * concurrent document creation serializes here rather than producing
 * duplicate numbers. Callers must already be inside a DB transaction
 * for that lock to mean anything -- see ContractController/
 * JobOrderController's `store()` methods.
 */
class Numbering
{
    public const PREFIXES = [
        'invoice' => 'INV',
        'receipt' => 'RV',
        'payment' => 'PV',
        'journal' => 'JV',
        'purchase_order' => 'PO',
        'supplier_invoice' => 'BILL',
        'quotation' => 'QUO',
        'contract' => 'CON',
        'job_order' => 'JO',
        'service_record' => 'SR',
        'bank_transaction' => 'BT',
        'incident' => 'INC',
        // Commission payout (6.5). Python passes the literal prefix "CP"
        // as the document KIND instead of registering it here -- see
        // App\Services\CommissionService's class docblock.
        'commission_payout' => 'CP',
        // Data Migration batch (docs/data-migration.md).
        'migration_batch' => 'MIG',
        // Prospect / Leads.
        'prospect' => 'PRS',
        // Credit notes (BILL-003), numbered when issued.
        'credit_note' => 'CN',
    ];

    /** Allocate the next number for this company/kind/year. */
    public static function next(string $companyId, string $docKind, ?\DateTimeInterface $on = null): string
    {
        $year = ($on ? Carbon::instance($on) : Carbon::today())->year;

        $row = DocumentSequence::where('company_id', $companyId)
            ->where('doc_kind', $docKind)
            ->where('year', $year)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            // Same as the Python version: insert then re-select under
            // lock, in case another transaction inserted first.
            DocumentSequence::firstOrCreate(
                ['company_id' => $companyId, 'doc_kind' => $docKind, 'year' => $year],
                ['last_number' => 0],
            );
            $row = DocumentSequence::where('company_id', $companyId)
                ->where('doc_kind', $docKind)
                ->where('year', $year)
                ->lockForUpdate()
                ->firstOrFail();
        }

        $row->last_number += 1;
        $row->save();

        return self::format($companyId, $docKind, $year, $row->last_number);
    }

    /**
     * Render a document number using this company's customization for
     * `docKind` (Document Control -- not yet converted), if any, else
     * the built-in default. Split out from next() so a future "preview
     * the next number" endpoint can call it without consuming one.
     */
    public static function format(string $companyId, string $docKind, int $year, int $number): string
    {
        $fmt = DocumentNumberFormat::where('company_id', $companyId)->where('doc_kind', $docKind)->first();

        if ($fmt) {
            $prefix = $fmt->prefix;
            $numberLength = $fmt->number_length;
            $includeYear = $fmt->include_year;
        } else {
            $prefix = self::PREFIXES[$docKind] ?? strtoupper(substr($docKind, 0, 3));
            $numberLength = 4;
            $includeYear = true;
        }

        $seq = str_pad((string) $number, $numberLength, '0', STR_PAD_LEFT);

        return $includeYear ? "{$prefix}-{$year}-{$seq}" : "{$prefix}-{$seq}";
    }
}
