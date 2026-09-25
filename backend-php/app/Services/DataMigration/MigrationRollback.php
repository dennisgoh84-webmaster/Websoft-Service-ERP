<?php

namespace App\Services\DataMigration;

use App\Models\AuditLogEntry;
use App\Models\CompanyIndividual;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Invoice;
use App\Models\JobOrder;
use App\Models\MigrationBatch;
use App\Models\MigrationRecordMap;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\QuotationLine;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Services\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Roll back an imported batch (decided 2026-09-25: "remove if
 * untouched"). It removes the records the batch CREATED, and only if
 * nothing has been done to any of them since the import: not edited
 * (any Event Log entry about the record after the import), and not used
 * by anything else (an invoice on the Company / Individual, an
 * allocation on the invoice, a later batch's records...). If even one
 * record is in use, nothing is removed and the blockers are listed.
 *
 * Records the batch only LINKED to (an existing Company / Individual
 * with the same UEN) are never removed. The batch, its record map (now
 * stamped rolled_back_at), a snapshot of every removed record, and the
 * Event Log stay forever -- so a roll back removes working data, never
 * the trail of it.
 */
class MigrationRollback
{
    /** Removal order: children before their parents. */
    private const ORDER = ['service_record', 'payment', 'invoice', 'quotation', 'job_order', 'contract', 'contact', 'company_individual', 'user'];

    private const MODELS = [
        'service_record' => ServiceRecord::class,
        'payment' => Payment::class,
        'invoice' => Invoice::class,
        'quotation' => Quotation::class,
        'job_order' => JobOrder::class,
        'contract' => Contract::class,
        'contact' => Contact::class,
        'company_individual' => CompanyIndividual::class,
        'user' => User::class,
    ];

    /** Table -> what a person calls it, for "in use by ..." messages. */
    private const TABLES = [
        'invoices' => 'Sales Invoices', 'payments' => 'Receipts', 'payment_allocations' => 'receipt allocations',
        'quotations' => 'Quotations', 'contracts' => 'Contracts', 'job_orders' => 'Job Orders',
        'service_records' => 'Service Records', 'contacts' => 'Contact persons', 'branches' => 'Branches',
        'company_individual_relationships' => 'Relationships', 'prospect_activities' => 'Prospect activities',
        'prospects' => 'Prospects',
        'incidents' => 'Incidents', 'portal_users' => 'Customer Portal logins', 'invoice_lines' => 'invoice lines',
        'excess_usage_records' => 'Excess Usage records', 'commission_payouts' => 'Commission Payouts',
        'contract_shared_customers' => 'contract hour-sharing', 'job_order_products' => 'Job Order products',
        'project_milestones' => 'project milestones', 'audit_log' => 'Event Logs', 'users' => 'Staff',
        'user_company_access' => 'staff company access', 'supplier_invoices' => 'Supplier Bills',
        'purchase_orders' => 'Purchase Orders', 'bank_transactions' => 'Bank Book',
    ];

    /**
     * @return array{rolled_back: bool, removed: int, blockers: array<int, array{record: string, reason: string}>}
     */
    public static function run(MigrationBatch $batch, User $actor, string $reason): array
    {
        if ($batch->status !== MigrationBatch::STATUS_SUCCEEDED) {
            throw new \InvalidArgumentException('Only an imported batch can be rolled back.');
        }
        if (trim($reason) === '') {
            throw new \InvalidArgumentException('A reason is required to roll back.');
        }

        $maps = MigrationRecordMap::live()->where('batch_id', $batch->id)->get();
        $created = $maps->where('action', MigrationRecordMap::ACTION_CREATED)
            ->sortBy(fn (MigrationRecordMap $m) => array_search($m->target_type, self::ORDER, true))
            ->values();

        $removed = [];
        $blockers = [];

        DB::beginTransaction();
        try {
            foreach ($created as $map) {
                $class = self::MODELS[$map->target_type] ?? null;
                $record = $class ? $class::find($map->target_id) : null;
                if ($record === null) {
                    continue;
                }
                $label = self::label($map->target_type, $record);

                $edit = AuditLogEntry::where('entity_id', $record->getKey())
                    ->where('at', '>', $batch->imported_at ?? $batch->finished_at)
                    ->orderBy('at')
                    ->first();
                if ($edit !== null) {
                    $blockers[] = ['record' => $label, 'reason' => sprintf(
                        'Changed since the import (%s by %s on %s).',
                        str_replace('_', ' ', $edit->action), $edit->actor_name ?? 'someone', $edit->at->timezone('Asia/Singapore')->format('d/m/Y H:i'),
                    )];

                    continue;
                }

                // Another batch (ZSOFT, say) may have linked its rows to
                // this record; the record map has no foreign key, so ask.
                $linkedBy = MigrationRecordMap::live()
                    ->where('target_id', $record->getKey())
                    ->where('batch_id', '!=', $batch->id)
                    ->first();
                if ($linkedBy !== null) {
                    $other = MigrationBatch::find($linkedBy->batch_id);
                    $blockers[] = ['record' => $label, 'reason' => sprintf(
                        'Linked to by %s batch %s -- roll that back first.', strtoupper($linkedBy->source), $other?->batch_number ?? '?',
                    )];

                    continue;
                }

                try {
                    DB::transaction(function () use ($map, $record) {
                        if ($map->target_type === 'quotation') {
                            QuotationLine::where('quotation_id', $record->getKey())->delete();
                        }
                        $record->delete();
                    });
                    $removed[] = ['type' => $map->target_type, 'label' => $label, 'record' => $record->getAttributes()];
                } catch (QueryException $e) {
                    $blockers[] = ['record' => $label, 'reason' => self::inUse($e)];
                }
            }
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        if ($blockers !== []) {
            DB::rollBack();
            $batch->forceFill(['rollback_report' => [
                'refused_at' => Carbon::now()->toIso8601String(),
                'reason' => $reason,
                'blockers' => array_slice($blockers, 0, 500),
                'blocked_count' => count($blockers),
                'removable_count' => count($removed),
            ]])->save();
            Audit::record(
                entityType: 'migration_batch', entityId: $batch->id, action: 'data_migration_rollback_refused',
                actorUserId: $actor->id, reason: $reason,
                details: "{$batch->batch_number}: roll back refused, nothing removed -- ".count($blockers).' record(s) in use',
            );

            return ['rolled_back' => false, 'removed' => 0, 'blockers' => $blockers];
        }

        MigrationRecordMap::whereIn('id', $maps->pluck('id'))->update(['rolled_back_at' => Carbon::now()]);
        $batch->fill([
            'status' => MigrationBatch::STATUS_ROLLED_BACK,
            'rolled_back_at' => Carbon::now(),
            'rolled_back_by_user_id' => $actor->id,
            'rollback_reason' => $reason,
            'rollback_report' => ['removed_count' => count($removed), 'linked_kept' => $maps->where('action', MigrationRecordMap::ACTION_LINKED)->count(), 'removed' => $removed],
        ])->save();
        Audit::record(
            entityType: 'migration_batch', entityId: $batch->id, action: 'data_migration_rolled_back',
            actorUserId: $actor->id, reason: $reason,
            details: "{$batch->batch_number}: rolled back, ".count($removed).' record(s) removed (snapshots kept on the batch)',
        );
        DB::commit();

        return ['rolled_back' => true, 'removed' => count($removed), 'blockers' => []];
    }

    private static function label(string $type, Model $record): string
    {
        return match ($type) {
            'service_record' => "Service Record {$record->service_record_number}",
            'payment' => "Receipt {$record->voucher_number}",
            'invoice' => "Invoice {$record->invoice_number}",
            'quotation' => "Quotation {$record->quotation_number}",
            'job_order' => "Job Order {$record->job_order_number}",
            'contract' => "Contract {$record->contract_number}",
            'contact' => "Contact person {$record->name}",
            'company_individual' => $record->name,
            'user' => "Staff user {$record->full_name}",
            default => (string) $record->getKey(),
        };
    }

    private static function inUse(QueryException $e): string
    {
        $message = $e->getPrevious()?->getMessage() ?? $e->getMessage();
        if (preg_match_all('/on table "([a-z_]+)"/', $message, $m) && count($m[1]) > 1) {
            $table = end($m[1]);

            return 'In use by '.(self::TABLES[$table] ?? str_replace('_', ' ', $table)).' -- roll back or remove those first.';
        }

        return 'In use elsewhere: '.strtok($message, "\n");
    }
}
