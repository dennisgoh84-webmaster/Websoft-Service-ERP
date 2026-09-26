<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\CreditNote;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\Invoice;
use App\Models\ModuleCatalog;
use App\Models\Prospect;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use App\Services\Posting;
use App\Services\SalesDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Credit Notes (BILL-003; #49, 2.7 / #38): raised against an invoice,
 * approved by Finance / the Sales Manager within the customer's limit and
 * by the owner above it (or with none set); issuing reverses the invoice's
 * revenue and GST, lowers what it owes, and counts in the GST Calculation.
 */
class CreditNoteTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private CompanyIndividual $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->customer = CompanyIndividual::factory()->for($this->company)->create(['name' => 'ACME']);
    }

    /** A signed-in user of this role, with FULL on billing. */
    private function as(string $role): array
    {
        $group = Group::factory()->for($this->company)->create();
        ModuleCatalog::firstOrCreate(['key' => 'billing'], ['name' => 'Billing', 'is_built' => true]);
        CompanyModule::firstOrCreate(['company_id' => $this->company->id, 'module_key' => 'billing'], ['enabled' => true]);
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'billing', 'access_level' => GroupModuleAuthority::FULL]);
        $user = User::factory()->for($this->company)->create(['role' => $role, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'group_id' => $group->id]);

        return ['Authorization' => 'Bearer '.$this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])->json('access_token')];
    }

    private function invoice(float $net = 1000, array $extra = []): Invoice
    {
        $invoice = Invoice::create(array_merge([
            'company_id' => $this->company->id, 'customer_id' => $this->customer->id,
            'invoice_number' => 'INV-2026-'.fake()->unique()->numerify('####'), 'invoice_type' => Invoice::TYPE_SALES,
            'description' => 'Switches', 'amount_sgd' => $net, 'tax_code' => 'SR', 'gst_rate' => 9,
            'gst_amount_sgd' => round($net * 0.09, 2), 'total_amount_sgd' => round($net * 1.09, 2),
        ], $extra));
        $invoice->forceFill(['issued_at' => now()])->save();

        return $invoice;
    }

    private function raise(array $h, Invoice $invoice, float $net, string $reason = 'Two switches returned faulty')
    {
        return $this->postJson('/api/credit-notes', ['invoice_id' => $invoice->id, 'amount_sgd' => $net, 'reason' => $reason], $h);
    }

    private function limit(?float $limit): void
    {
        $this->customer->forceFill(['credit_note_approval_limit_sgd' => $limit])->save();
    }

    public function test_within_the_limit_it_is_issued_straight_away_posted_and_taken_off_the_invoice(): void
    {
        $this->limit(500);
        $invoice = $this->invoice(1000); // 1,090.00
        $finance = $this->as(User::ROLE_FINANCE);

        // Within the customer's limit nobody approves it (Dennis, 2026-09-26).
        $issued = $this->raise($finance, $invoice, 200)->assertOk()
            ->assertJson(['status' => 'issued', 'gl_status' => 'posted', 'amount_sgd' => 200, 'gst_amount_sgd' => 18, 'total_amount_sgd' => 218, 'needs_owner' => false]);
        $this->assertMatchesRegularExpression('/^CN-\d{4}-0001$/', $issued->json('credit_note_number'));

        $invoice->refresh();
        $this->assertEqualsWithDelta(218, (float) $invoice->credited_sgd, 0.001);
        $this->assertEqualsWithDelta(872, $invoice->outstandingSgd()->toFloat(), 0.001);
        $this->assertSame(Invoice::STATUS_PARTIALLY_PAID, $invoice->status);

        // The invoice's entry in reverse: Dr 4030 revenue 200 / Dr 2100 GST 18 / Cr 1100 AR 218.
        $lines = [];
        foreach (Posting::liveEntryFor(Posting::SOURCE_CREDIT_NOTE, $issued->json('id'))->lines as $l) {
            $lines[Account::find($l->account_id)->code] = [(float) $l->debit_sgd, (float) $l->credit_sgd];
        }
        $this->assertSame(['4030' => [200.0, 0.0], '2100' => [18.0, 0.0], '1100' => [0.0, 218.0]], $lines);
        $this->assertDatabaseHas('audit_log_entries', ['entity_type' => 'credit_note', 'entity_id' => $issued->json('id'), 'action' => 'issued']);
    }

    public function test_above_the_limit_or_with_none_set_only_the_owner_approves(): void
    {
        $invoice = $this->invoice(1000);
        $finance = $this->as(User::ROLE_FINANCE);
        $owner = $this->as(User::ROLE_OWNER);

        // No limit set: it waits, and the owner approves every credit note.
        $cn = $this->raise($finance, $invoice, 100)->assertJson(['status' => 'pending_approval', 'needs_owner' => true, 'can_approve' => false]);
        $this->assertEqualsWithDelta(1090, $invoice->fresh()->outstandingSgd()->toFloat(), 0.001, 'nothing moves while it waits');
        $this->postJson("/api/credit-notes/{$cn->json('id')}/approve", [], $finance)->assertStatus(422);
        $this->postJson("/api/credit-notes/{$cn->json('id')}/approve", [], $this->as(User::ROLE_SALES_MANAGER))->assertStatus(422);

        // Above a limit: only the owner.
        $this->limit(500);
        $big = $this->raise($finance, $invoice, 600)->assertJson(['status' => 'pending_approval', 'needs_owner' => true]); // 654 > 500
        $this->postJson("/api/credit-notes/{$big->json('id')}/approve", [], $this->as(User::ROLE_SALES_MANAGER))->assertStatus(422);
        $this->postJson("/api/credit-notes/{$big->json('id')}/approve", [], $owner)->assertOk()->assertJson(['status' => 'issued']);
        $this->postJson("/api/credit-notes/{$cn->json('id')}/approve", [], $owner)->assertOk();
    }

    public function test_a_credit_note_takes_off_no_more_than_the_invoice_total_less_other_credit_notes(): void
    {
        $this->limit(10000);
        $invoice = $this->invoice(1000); // 1,090.00
        $finance = $this->as(User::ROLE_FINANCE);

        $this->raise($finance, $invoice, 1001)->assertStatus(422); // 1,091.09
        $this->raise($finance, $invoice, 600)->assertOk(); // 654, issued
        // What is already credited counts: 1,090 - 654 = 436 left.
        $this->raise($finance, $invoice, 401)->assertStatus(422); // 437.09
        $this->raise($finance, $invoice, 400)->assertOk(); // 436.00
        $this->raise($finance, $invoice, 1)->assertStatus(422);

        // A reason is always needed.
        $this->raise($finance, $this->invoice(100), 10, ' ')->assertStatus(422);
    }

    public function test_crediting_the_whole_invoice_leaves_it_credited_with_nothing_owed(): void
    {
        $this->limit(10000);
        $invoice = $this->invoice(1000);
        $finance = $this->as(User::ROLE_FINANCE);
        $this->raise($finance, $invoice, 1000)->assertOk()->assertJson(['status' => 'issued']);

        $invoice->refresh();
        $this->assertSame(Invoice::STATUS_CREDITED, $invoice->status);
        $this->assertEqualsWithDelta(0, $invoice->outstandingSgd()->toFloat(), 0.001);
        $this->getJson("/api/invoices/{$invoice->id}", $finance)->assertJson(['credited_sgd' => 1090, 'outstanding_sgd' => 0]);
    }

    public function test_rejected_and_withdrawn_credit_notes_are_kept_and_change_nothing(): void
    {
        $invoice = $this->invoice(1000); // no limit: each waits for the owner
        $finance = $this->as(User::ROLE_FINANCE);
        $owner = $this->as(User::ROLE_OWNER);

        $a = $this->raise($finance, $invoice, 100)->assertOk();
        $this->postJson("/api/credit-notes/{$a->json('id')}/reject", ['reason' => 'Not agreed with the customer'], $owner)->assertOk()
            ->assertJson(['status' => 'rejected', 'decision_note' => 'Not agreed with the customer']);
        $b = $this->raise($finance, $invoice, 100)->assertOk();
        $this->postJson("/api/credit-notes/{$b->json('id')}/withdraw", [], $finance)->assertOk()->assertJson(['status' => 'withdrawn']);

        $this->assertEqualsWithDelta(1090, $invoice->fresh()->outstandingSgd()->toFloat(), 0.001);
        $this->assertNull(Posting::liveEntryFor(Posting::SOURCE_CREDIT_NOTE, $a->json('id')));
        $this->assertSame(2, CreditNote::count());
        $this->postJson("/api/credit-notes/{$a->json('id')}/approve", [], $owner)->assertStatus(422);
        $this->getJson('/api/credit-notes?status=rejected', $finance)->assertOk()->assertJsonCount(1);
    }

    public function test_an_issued_credit_note_counts_against_its_month_in_the_gst_calculation(): void
    {
        $this->limit(10000);
        $owner = $this->as(User::ROLE_OWNER);
        $invoice = $this->invoice(1000);
        $cn = $this->raise($owner, $invoice, 200)->assertOk();
        // Within the limit it was issued when raised (2026-09-26).

        $start = now()->startOfMonth()->toDateString();
        $end = now()->endOfMonth()->toDateString();
        $period = $this->postJson('/api/accounting-periods', ['fiscal_year' => (int) now()->year, 'name' => 'This month', 'period_start' => $start, 'period_end' => $end], $owner)
            ->assertOk()->json('id');
        $this->postJson("/api/accounting-periods/{$period}/close", [], $owner)->assertOk();
        $r = $this->postJson("/api/accounting-periods/{$period}/gst-calculate", [], $owner)->assertOk()->json();

        $box = collect($r['boxes'])->pluck('amount_sgd', 'box');
        $this->assertEquals(800, $box[1], 'standard-rated supplies: 1,000 less the 200 credited');
        $this->assertEquals(72, $box[6], 'output tax: 90 less the 18 credited');
        $this->assertContains('credit_note', array_column($r['lines'], 'document_type'));
    }

    public function test_sales_figures_count_an_invoice_net_of_its_issued_credit_notes(): void
    {
        $prospect = Prospect::create([
            'company_id' => $this->company->id, 'prospect_number' => 'PRS-2026-0001', 'customer_id' => $this->customer->id,
            'title' => 'Switches', 'status' => 'open',
        ]);
        $invoice = $this->invoice(1000, ['prospect_id' => $prospect->id]); // 1,090.00
        $finance = $this->as(User::ROLE_FINANCE);
        $this->limit(500);
        // A pending one takes nothing off.
        $this->limit(100);
        $pending = $this->raise($finance, $invoice, 200)->assertOk()->assertJson(['status' => 'pending_approval']);
        $this->assertEqualsWithDelta(1090, $prospect->amounts()['billed_amount_sgd'], 0.001);
        $this->postJson("/api/credit-notes/{$pending->json('id')}/withdraw", [], $finance)->assertOk();
        // Within the limit it is issued when raised (2026-09-26).
        $this->limit(500);
        $this->raise($finance, $invoice, 200)->assertOk()->assertJson(['status' => 'issued']); // 218.00
        $amounts = $prospect->fresh()->amounts();
        $this->assertEqualsWithDelta(872, $amounts['billed_amount_sgd'], 0.001);
        $this->assertEqualsWithDelta(872, $amounts['outstanding_amount_sgd'], 0.001);
        // Top 10 billing is net of GST: 1,000 less the credit note's 200.
        $top = SalesDashboardService::topBillingCustomers($this->company->id)->firstWhere('customer_id', $this->customer->id);
        $this->assertEqualsWithDelta(800, $top['net_revenue_sgd'], 0.001);
        // The salesperson cards' billed figure (here the prospect has no salesperson).
        $owner = User::factory()->for($this->company)->create(['role' => User::ROLE_OWNER]);
        $card = collect(SalesDashboardService::salespersonCards($owner))->firstWhere('kind', 'no_salesperson');
        $this->assertEqualsWithDelta(872, $card['billed_sgd'], 0.001);
        $this->assertEqualsWithDelta(872, $card['billed_month_sgd'], 0.001);
    }

    public function test_only_an_issued_credit_note_prints_and_another_companys_is_not_found(): void
    {
        $finance = $this->as(User::ROLE_FINANCE);
        $pending = $this->raise($finance, $this->invoice(100), 50)->assertOk(); // no limit: waits for the owner
        $this->get("/api/credit-notes/{$pending->json('id')}/export.docx", $finance)->assertStatus(409);
        // Within the limit it is issued when raised (2026-09-26), and prints.
        $this->limit(10000);
        $cn = $this->raise($finance, $this->invoice(100), 50)->assertOk();
        $this->get("/api/credit-notes/{$cn->json('id')}/export.docx", $finance)->assertOk();

        $other = Company::factory()->create();
        $theirs = CreditNote::create(['company_id' => $other->id, 'invoice_id' => $this->invoice(10)->id, 'customer_id' => $this->customer->id,
            'reason' => 'x', 'amount_sgd' => 1, 'total_amount_sgd' => 1]);
        $this->getJson("/api/credit-notes/{$theirs->id}", $finance)->assertStatus(404);
    }
}
