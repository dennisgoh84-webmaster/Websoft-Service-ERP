<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Multi-currency on sales and purchases (Dennis, 2026-09-26, decision
 * page; see App\Services\Currency).
 *
 * Every document keeps its figures twice: in its own currency (the new
 * *_fx columns, with currency_code and exchange_rate -- "1 unit = X
 * SGD") and in SGD (the existing *_sgd columns, which every report, the
 * General Ledger and the GST return keep reading). Existing documents
 * are all SGD: currency SGD, rate 1, and each *_fx figure equal to its
 * *_sgd one.
 *
 * - company_individuals.default_currency: the party's own currency,
 *   which a new document starts in (blank = SGD).
 * - Allocations keep the amount in the documents' currency (amount_fx),
 *   what it cleared off the invoice / bill in SGD at that document's
 *   rate (invoice_amount_sgd / bill_amount_sgd), and the realised
 *   exchange difference booked when it was made (fx_difference_sgd).
 * - A foreign-currency bank account (bank_accounts.currency_code, which
 *   already existed) runs its Bank Book in that currency, with the SGD
 *   value beside each line (bank_transactions.debit_fx / credit_fx,
 *   bank_accounts.opening_balance_fx).
 * - GL account 6800 "Exchange (gain) / loss" is added to every company
 *   chart that lacks it; each one added is written to Event Logs.
 */
return new class extends Migration
{
    private const HEADERS = [
        'quotations' => ['amount', 'gst_amount', 'total_amount'],
        'invoices' => ['amount', 'gst_amount', 'total_amount', 'amount_paid', 'credited'],
        'payments' => ['amount'],
        'purchase_orders' => ['amount', 'gst_amount', 'total_amount'],
        'supplier_invoices' => ['amount', 'gst_amount', 'total_amount', 'amount_paid'],
        'supplier_payments' => ['amount'],
        'credit_notes' => ['amount', 'gst_amount', 'total_amount'],
    ];

    private const LINES = [
        'quotation_lines' => ['unit_price', 'line_total'],
        'invoice_lines' => ['unit_price', 'line_amount'],
    ];

    public function up(): void
    {
        Schema::table('company_individuals', function (Blueprint $table) {
            $table->string('default_currency', 3)->nullable();
        });

        foreach (self::HEADERS as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                $table->string('currency_code', 3)->default('SGD');
                $table->decimal('exchange_rate', 18, 6)->default(1);
                foreach ($columns as $c) {
                    $table->decimal("{$c}_fx", 14, 2)->nullable();
                }
            });
            DB::table($tableName)->update(array_combine(
                array_map(fn ($c) => "{$c}_fx", $columns),
                array_map(fn ($c) => DB::raw("{$c}_sgd"), $columns),
            ));
        }
        foreach (self::LINES as $tableName => $columns) {
            Schema::table($tableName, function (Blueprint $table) use ($columns) {
                foreach ($columns as $c) {
                    $table->decimal("{$c}_fx", 14, 2)->nullable();
                }
            });
            DB::table($tableName)->update(array_combine(
                array_map(fn ($c) => "{$c}_fx", $columns),
                array_map(fn ($c) => DB::raw("{$c}_sgd"), $columns),
            ));
        }

        Schema::table('payment_allocations', function (Blueprint $table) {
            $table->decimal('amount_fx', 14, 2)->nullable();
            $table->decimal('invoice_amount_sgd', 14, 2)->nullable();
            $table->decimal('fx_difference_sgd', 14, 2)->default(0);
        });
        DB::table('payment_allocations')->update(['amount_fx' => DB::raw('amount_sgd'), 'invoice_amount_sgd' => DB::raw('amount_sgd')]);
        Schema::table('supplier_payment_allocations', function (Blueprint $table) {
            $table->decimal('amount_fx', 14, 2)->nullable();
            $table->decimal('bill_amount_sgd', 14, 2)->nullable();
            $table->decimal('fx_difference_sgd', 14, 2)->default(0);
        });
        DB::table('supplier_payment_allocations')->update(['amount_fx' => DB::raw('amount_sgd'), 'bill_amount_sgd' => DB::raw('amount_sgd')]);

        Schema::table('bank_accounts', function (Blueprint $table) {
            $table->decimal('opening_balance_fx', 14, 2)->nullable();
        });
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->decimal('debit_fx', 14, 2)->nullable();
            $table->decimal('credit_fx', 14, 2)->nullable();
        });

        $now = Carbon::now();
        foreach (DB::table('companies')->pluck('id') as $companyId) {
            if (! DB::table('accounts')->where('company_id', $companyId)->exists()
                || DB::table('accounts')->where('company_id', $companyId)->where('code', '6800')->exists()) {
                continue;
            }
            $id = (string) Str::uuid();
            $row = ['id' => $id, 'company_id' => $companyId, 'code' => '6800', 'name' => 'Exchange (gain) / loss', 'account_type' => 'expense'];
            if (Schema::hasColumn('accounts', 'is_active')) {
                $row['is_active'] = true;
            }
            if (Schema::hasColumn('accounts', 'created_at')) {
                $row['created_at'] = $now;
            }
            DB::table('accounts')->insert($row);
            DB::table('audit_log_entries')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $companyId, 'entity_type' => 'account', 'entity_id' => $id,
                'action' => 'created', 'actor_name' => 'System (migration)', 'at' => $now,
                'details' => '6800 Exchange (gain) / loss added for realised exchange differences (multi-currency, 2026-09-26)',
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('bank_transactions', fn (Blueprint $t) => $t->dropColumn(['debit_fx', 'credit_fx']));
        Schema::table('bank_accounts', fn (Blueprint $t) => $t->dropColumn('opening_balance_fx'));
        Schema::table('supplier_payment_allocations', fn (Blueprint $t) => $t->dropColumn(['amount_fx', 'bill_amount_sgd', 'fx_difference_sgd']));
        Schema::table('payment_allocations', fn (Blueprint $t) => $t->dropColumn(['amount_fx', 'invoice_amount_sgd', 'fx_difference_sgd']));
        foreach (self::LINES as $tableName => $columns) {
            Schema::table($tableName, fn (Blueprint $t) => $t->dropColumn(array_map(fn ($c) => "{$c}_fx", $columns)));
        }
        foreach (self::HEADERS as $tableName => $columns) {
            Schema::table($tableName, fn (Blueprint $t) => $t->dropColumn([...array_map(fn ($c) => "{$c}_fx", $columns), 'currency_code', 'exchange_rate']));
        }
        Schema::table('company_individuals', fn (Blueprint $t) => $t->dropColumn('default_currency'));
    }
};
