<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AuditLogEntry;
use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Group;
use App\Models\GroupModuleAuthority;
use App\Models\JournalEntry;
use App\Models\ModuleCatalog;
use App\Models\User;
use App\Models\UserCompanyAccess;
use App\Services\PasswordPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Manual Journal Voucher CRUD -- the KNOWN GAP recorded when GL posting
 * was converted, closed 2026-09-15. Converted from the
 * /ledger/vouchers* half of backend/app/routers/ledger.py.
 */
class JournalVoucherTest extends TestCase
{
    use RefreshDatabase;

    private const MODULE = 'finance_accounting';

    private Company $company;

    private string $token;

    private Account $cash;

    private Account $revenue;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->token = $this->ownerToken($this->company);
        $this->cash = Account::create([
            'company_id' => $this->company->id, 'code' => '1001',
            'name' => 'Cash at Bank', 'account_type' => Account::TYPE_ASSET,
        ]);
        $this->revenue = Account::create([
            'company_id' => $this->company->id, 'code' => '4001',
            'name' => 'Sales Revenue', 'account_type' => Account::TYPE_REVENUE,
        ]);
    }

    private function enableModule(Company $company): void
    {
        ModuleCatalog::firstOrCreate(['key' => self::MODULE], ['name' => 'Finance / Accounting', 'is_built' => true]);
        CompanyModule::updateOrCreate(
            ['company_id' => $company->id, 'module_key' => self::MODULE],
            ['enabled' => true],
        );
    }

    private function ownerToken(Company $company): string
    {
        $owner = User::factory()->for($company)->create([
            'role' => User::ROLE_OWNER, 'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        $this->enableModule($company);

        return $this->post('/api/auth/login', ['username' => $owner->email, 'password' => 'demo1234'])
            ->json('access_token');
    }

    private function headers(?string $token = null): array
    {
        return ['Authorization' => 'Bearer '.($token ?? $this->token)];
    }

    /** @return array<string, mixed> */
    private function raise(bool $post = false, float $debit = 500.0, float $credit = 500.0): array
    {
        return $this->postJson('/api/ledger/vouchers', [
            'entry_date' => '2026-06-01',
            'narration' => 'Manual correction',
            'post' => $post,
            'lines' => [
                ['account_id' => $this->cash->id, 'debit_sgd' => $debit, 'description' => 'Debit side'],
                ['account_id' => $this->revenue->id, 'credit_sgd' => $credit, 'description' => 'Credit side'],
            ],
        ], $this->headers())->json();
    }

    public function test_a_voucher_is_a_draft_unless_post_is_asked_for(): void
    {
        $draft = $this->raise(post: false);

        $this->assertSame(JournalEntry::STATUS_DRAFT, $draft['status']);
        $this->assertTrue($draft['is_balanced']);
        $this->assertEqualsWithDelta(500.0, $draft['total_debit'], 0.01);
        $this->assertCount(2, $draft['lines']);
        // The joined account code/name come back, so the screen can
        // render a line without a request per row.
        $this->assertSame('1001', $draft['lines'][0]['account_code']);
    }

    public function test_post_on_create_posts_it_immediately(): void
    {
        $posted = $this->raise(post: true);

        $this->assertSame(JournalEntry::STATUS_POSTED, $posted['status']);
        $this->assertSame(1, AuditLogEntry::where('entity_type', 'journal_entry')
            ->where('action', 'posted')->count());
    }

    public function test_an_unbalanced_voucher_is_refused(): void
    {
        $response = $this->postJson('/api/ledger/vouchers', [
            'entry_date' => '2026-06-01',
            'narration' => 'Does not balance',
            'post' => true,
            'lines' => [
                ['account_id' => $this->cash->id, 'debit_sgd' => 500],
                ['account_id' => $this->revenue->id, 'credit_sgd' => 400],
            ],
        ], $this->headers());

        $response->assertStatus(422);
        // Nothing was written -- a refusal is whole.
        $this->assertSame(0, JournalEntry::where('company_id', $this->company->id)->count());
    }

    public function test_a_draft_can_be_posted_later(): void
    {
        $draft = $this->raise(post: false);

        $this->postJson("/api/ledger/vouchers/{$draft['id']}/post", [], $this->headers())
            ->assertOk()->assertJsonPath('status', JournalEntry::STATUS_POSTED);
    }

    public function test_reversal_writes_a_mirror_and_leaves_the_original(): void
    {
        $posted = $this->raise(post: true);

        $reversal = $this->postJson("/api/ledger/vouchers/{$posted['id']}/reverse", [
            'reason' => 'Keyed against the wrong account',
        ], $this->headers())->assertOk()->json();

        // The endpoint returns the REVERSAL, as Python does.
        $this->assertNotSame($posted['id'], $reversal['id']);
        $this->assertSame($posted['id'], $reversal['reverses_entry_id']);
        // Debit and credit are mirrored.
        $this->assertEqualsWithDelta(500.0, $reversal['total_debit'], 0.01);
        $this->assertEqualsWithDelta(500.0, $reversal['total_credit'], 0.01);

        // CLAUDE.md forbids deleting financial records: the original is
        // still there, now marked reversed, and the mistake and its
        // correction both remain on record.
        $original = JournalEntry::findOrFail($posted['id']);
        $this->assertSame(JournalEntry::STATUS_REVERSED, $original->status);
        $this->assertSame(2, JournalEntry::where('company_id', $this->company->id)->count());

        $entry = AuditLogEntry::where('action', 'reversed')->firstOrFail();
        $this->assertSame('Keyed against the wrong account', $entry->reason);
    }

    public function test_a_reversal_needs_a_reason(): void
    {
        $posted = $this->raise(post: true);

        $this->postJson("/api/ledger/vouchers/{$posted['id']}/reverse", [], $this->headers())
            ->assertStatus(422);
        $this->postJson("/api/ledger/vouchers/{$posted['id']}/reverse", ['reason' => ''], $this->headers())
            ->assertStatus(422);
    }

    public function test_a_line_naming_another_companys_account_is_refused(): void
    {
        $other = Company::factory()->create();
        $theirAccount = Account::create([
            'company_id' => $other->id, 'code' => '9999',
            'name' => 'Theirs', 'account_type' => Account::TYPE_ASSET,
        ]);

        $this->postJson('/api/ledger/vouchers', [
            'entry_date' => '2026-06-01', 'narration' => 'Cross-company',
            'lines' => [
                ['account_id' => $theirAccount->id, 'debit_sgd' => 100],
                ['account_id' => $this->revenue->id, 'credit_sgd' => 100],
            ],
        ], $this->headers())->assertStatus(404);
    }

    public function test_the_list_filters_by_type_and_status(): void
    {
        $this->raise(post: false);
        $this->raise(post: true, debit: 900, credit: 900);

        $this->getJson('/api/ledger/vouchers', $this->headers())->assertOk()->assertJsonCount(2);
        $this->getJson('/api/ledger/vouchers?status=draft', $this->headers())->assertOk()->assertJsonCount(1);
        $this->getJson('/api/ledger/vouchers?voucher_type=journal', $this->headers())->assertOk()->assertJsonCount(2);
        $this->getJson('/api/ledger/vouchers?voucher_type=receipt', $this->headers())->assertOk()->assertJsonCount(0);
    }

    public function test_another_companys_voucher_is_not_found(): void
    {
        $posted = $this->raise(post: true);
        $otherToken = $this->ownerToken(Company::factory()->create());

        $this->getJson("/api/ledger/vouchers/{$posted['id']}", $this->headers($otherToken))->assertStatus(404);
        $this->postJson("/api/ledger/vouchers/{$posted['id']}/reverse", ['reason' => 'x'], $this->headers($otherToken))
            ->assertStatus(404);
    }

    public function test_posting_and_reversing_need_full_while_raising_needs_only_edit(): void
    {
        $this->enableModule($this->company);
        $group = Group::factory()->for($this->company)->create();
        GroupModuleAuthority::create([
            'group_id' => $group->id, 'module_key' => self::MODULE, 'access_level' => GroupModuleAuthority::EDIT,
        ]);
        $user = User::factory()->for($this->company)->create([
            'role' => User::ROLE_SUPPORT_ENGINEER, 'hashed_password' => PasswordPolicy::hash('demo1234'),
        ]);
        UserCompanyAccess::create(['user_id' => $user->id, 'company_id' => $this->company->id, 'group_id' => $group->id]);
        $editToken = $this->post('/api/auth/login', ['username' => $user->email, 'password' => 'demo1234'])
            ->json('access_token');

        // EDIT may raise a draft...
        $draft = $this->postJson('/api/ledger/vouchers', [
            'entry_date' => '2026-06-01', 'narration' => 'Raised at edit level',
            'lines' => [
                ['account_id' => $this->cash->id, 'debit_sgd' => 10],
                ['account_id' => $this->revenue->id, 'credit_sgd' => 10],
            ],
        ], $this->headers($editToken))->assertOk()->json();

        // ...but committing it to the ledger is a FULL-level act.
        $this->postJson("/api/ledger/vouchers/{$draft['id']}/post", [], $this->headers($editToken))
            ->assertStatus(403);
    }

    public function test_the_voucher_export_carries_two_decimal_totals(): void
    {
        $this->raise(post: true);

        $csv = $this->get('/api/ledger/vouchers/export.csv', $this->headers())->assertOk()->getContent();
        $this->assertStringContainsString('voucher_number,voucher_type,entry_date,narration,status', $csv);
        $this->assertStringContainsString('500.00', $csv);

        $xlsx = $this->get('/api/ledger/vouchers/export.xlsx', $this->headers())->assertOk()->getContent();
        $this->assertStringStartsWith("PK\x03\x04", $xlsx);
    }

    public function test_the_trial_balance_and_account_ledger_export(): void
    {
        $this->raise(post: true);

        $tb = $this->get('/api/ledger/trial-balance/export.csv', $this->headers())->assertOk()->getContent();
        $this->assertStringContainsString('code,name,account_type,debit_sgd,credit_sgd,balance_sgd', $tb);
        $this->assertStringContainsString('1001', $tb);

        $gl = $this->get("/api/ledger/transactions/{$this->cash->id}/export.csv", $this->headers())
            ->assertOk()->getContent();
        $this->assertStringContainsString('voucher_number,voucher_type,entry_date,narration', $gl);

        $this->assertStringStartsWith("PK\x03\x04",
            $this->get('/api/ledger/trial-balance/export.xlsx', $this->headers())->assertOk()->getContent());
    }
}
