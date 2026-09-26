<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\Invoice;
use App\Models\ModuleCatalog;
use App\Models\Prospect;
use App\Models\Quotation;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sales Dashboard per-salesperson cards (decision 12.2, Dennis 2026-09-26):
 * work counts on its prospect's salesperson; managers see every card,
 * Sales Staff only their own.
 */
class SalespersonCardsTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private CompanyIndividual $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-09-26 10:00'));
        $this->company = Company::factory()->create(['financial_year_start_month' => 7]);
        $this->customer = CompanyIndividual::factory()->for($this->company)->create();
    }

    private function login(string $role, string $name): array
    {
        $group = Group::factory()->for($this->company)->create();
        ModuleCatalog::firstOrCreate(['key' => 'reporting'], ['name' => 'Reporting', 'is_built' => true]);
        CompanyModule::updateOrCreate(['company_id' => $this->company->id, 'module_key' => 'reporting'], ['enabled' => true]);
        GroupModuleAuthority::create(['group_id' => $group->id, 'module_key' => 'reporting', 'access_level' => GroupModuleAuthority::VIEW]);
        $u = User::factory()->for($this->company)->create(['role' => $role, 'full_name' => $name, 'hashed_password' => PasswordPolicy::hash('demo1234')]);
        UserCompanyAccess::create(['user_id' => $u->id, 'company_id' => $this->company->id, 'group_id' => $group->id]);

        return [$u, ['Authorization' => 'Bearer '.$this->post('/api/auth/login', ['username' => $u->email, 'password' => 'demo1234'])->json('access_token')]];
    }

    private function prospect(?User $salesperson, string $status): Prospect
    {
        return Prospect::factory()->create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'salesperson_user_id' => $salesperson?->id, 'status' => $status]);
    }

    private function quotation(?Prospect $p, float $total, string $status = Quotation::STATUS_SENT, string $date = '2026-09-01'): void
    {
        Quotation::factory()->create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'prospect_id' => $p?->id,
            'status' => $status, 'quotation_date' => $date, 'total_amount_sgd' => $total]);
    }

    private function invoice(?Prospect $p, float $total, float $paid, string $issued = '2026-09-10 10:00:00+08'): void
    {
        $inv = Invoice::create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'invoice_number' => 'INV-'.uniqid(),
            'invoice_type' => Invoice::TYPE_SALES, 'description' => 'x', 'amount_sgd' => $total, 'gst_amount_sgd' => 0,
            'total_amount_sgd' => $total, 'amount_paid_sgd' => $paid, 'prospect_id' => $p?->id]);
        DB::table('invoices')->where('id', $inv->id)->update(['issued_at' => $issued]);
    }

    public function test_work_counts_on_the_prospects_salesperson_and_managers_see_every_card(): void
    {
        [, $mgrH] = $this->login(User::ROLE_SALES_MANAGER, 'Cherish');
        [$amy, $amyH] = $this->login(User::ROLE_SALES_STAFF, 'Amy');
        [$ben] = $this->login(User::ROLE_SALES_STAFF, 'Ben');

        $a1 = $this->prospect($amy, Prospect::STATUS_PROPOSAL);
        $this->prospect($amy, Prospect::STATUS_NEW);
        $b1 = $this->prospect($ben, Prospect::STATUS_WON);
        $this->quotation($a1, 1000);
        $this->quotation($a1, 500, Quotation::STATUS_DRAFT);              // not quoted yet
        $this->quotation($a1, 800, Quotation::STATUS_SENT, '2026-05-01'); // last financial year (FY starts July)
        $this->invoice($a1, 1090, 500);
        $this->invoice($b1, 2000, 2000);
        $this->quotation(null, 300);   // no prospect
        $this->invoice(null, 400, 0);

        $cards = collect($this->getJson('/api/sales-dashboard/salespeople', $mgrH)->assertOk()->assertJsonPath('sees_all', true)->json('cards'))->keyBy('name');

        $this->assertSame(1000.0, (float) $cards['Amy']['quoted_sgd']);
        $this->assertSame(1090.0, (float) $cards['Amy']['billed_sgd']);
        $this->assertSame(500.0, (float) $cards['Amy']['paid_sgd']);
        $this->assertSame(1, $cards['Amy']['prospects_by_stage']['proposal']);
        $this->assertSame(2, $cards['Amy']['open_prospects']);
        $this->assertSame(1, $cards['Ben']['prospects_by_stage']['won']);
        $this->assertSame(2000.0, (float) $cards['Ben']['billed_sgd']);
        $this->assertSame(300.0, (float) $cards['No prospect']['quoted_sgd']);
        $this->assertSame(400.0, (float) $cards['No prospect']['billed_sgd']);
        $this->assertTrue($cards->has('Cherish'), 'every sales role has a card');

        // Sales Staff see only their own card.
        $own = $this->getJson('/api/sales-dashboard/salespeople', $amyH)->assertOk()->assertJsonPath('sees_all', false)->json('cards');
        $this->assertSame(['Amy'], array_column($own, 'name'));
        $this->assertSame(1090.0, (float) $own[0]['billed_sgd']);
    }
}
