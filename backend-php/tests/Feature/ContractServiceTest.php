<?php

namespace Tests\Feature;

use App\Exceptions\ContractRuleViolation;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\ContractSharedCustomer;
use App\Models\User;
use App\Services\ContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit-level coverage of the SRV-001..018 business logic in
 * App\Services\ContractService, mirroring
 * backend/app/services/contracts.py exactly. This is the module
 * docs/php-conversion-plan.md singled out as needing a dedicated test
 * suite, not just a smoke test, given how much rides on getting the
 * rounding/eligibility/balance-never-negative arithmetic right.
 */
class ContractServiceTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: CompanyIndividual, 1: string} [customer, actorUserId] */
    private function customerAndActor(): array
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $actor = User::factory()->for($company)->create();

        return [$customer, $actor->id];
    }

    // ---- SRV-002 / SRV-012: 10-hour minimum, no override ----------------

    public function test_service_support_contract_below_minimum_hours_is_rejected(): void
    {
        [$customer, $actorId] = $this->customerAndActor();

        $this->expectException(ContractRuleViolation::class);
        $this->expectExceptionMessage('SRV-002');

        ContractService::createContract(
            companyId: $customer->company_id, customerId: $customer->id,
            contractedHours: 9.99, contractValueSgd: 1000, startDate: '2026-01-01', actorUserId: $actorId,
        );
    }

    public function test_service_support_contract_at_exactly_minimum_hours_is_allowed(): void
    {
        [$customer, $actorId] = $this->customerAndActor();

        $contract = ContractService::createContract(
            companyId: $customer->company_id, customerId: $customer->id,
            contractedHours: 10, contractValueSgd: 1000, startDate: '2026-01-01', actorUserId: $actorId,
        );

        $this->assertSame(600, $contract->contracted_minutes);
    }

    // ---- SRV-001: 12-month term, contract kinds ---------------------------

    public function test_end_date_is_twelve_months_after_start_date(): void
    {
        [$customer, $actorId] = $this->customerAndActor();

        $contract = ContractService::createContract(
            companyId: $customer->company_id, customerId: $customer->id,
            contractedHours: 10, contractValueSgd: 1000, startDate: '2026-01-31', actorUserId: $actorId,
        );

        // Feb has no 31st -- matches Python's dateutil.relativedelta
        // clamping behaviour, not a naive +365 days.
        $this->assertSame('2027-01-31', $contract->end_date->toDateString());
    }

    public function test_annual_contract_ignores_supplied_hours(): void
    {
        [$customer, $actorId] = $this->customerAndActor();

        $contract = ContractService::createContract(
            companyId: $customer->company_id, customerId: $customer->id,
            contractedHours: 999, contractValueSgd: 5000, startDate: '2026-01-01',
            actorUserId: $actorId, contractKind: Contract::KIND_ANNUAL,
        );

        $this->assertSame(0, $contract->contracted_minutes);
    }

    public function test_ad_hoc_contract_requires_positive_hourly_rate(): void
    {
        [$customer, $actorId] = $this->customerAndActor();

        $this->expectException(ContractRuleViolation::class);

        ContractService::createContract(
            companyId: $customer->company_id, customerId: $customer->id,
            contractedHours: 0, contractValueSgd: 0, startDate: '2026-01-01',
            actorUserId: $actorId, contractKind: Contract::KIND_AD_HOC,
        );
    }

    public function test_ad_hoc_contract_has_zero_value_even_if_supplied(): void
    {
        [$customer, $actorId] = $this->customerAndActor();

        $contract = ContractService::createContract(
            companyId: $customer->company_id, customerId: $customer->id,
            contractedHours: 0, contractValueSgd: 9999, startDate: '2026-01-01',
            actorUserId: $actorId, contractKind: Contract::KIND_AD_HOC, hourlyRateSgd: 150,
        );

        $this->assertSame('0.00', (string) $contract->contract_value_sgd);
    }

    // ---- Activation ---------------------------------------------------

    public function test_only_a_draft_contract_can_be_activated(): void
    {
        [$contract, $actorId] = $this->activeContract();

        $this->expectException(ContractRuleViolation::class);
        ContractService::activateContract($contract, $actorId);
    }

    // ---- SRV-004: deduction never negative, exceeded transition ---------

    public function test_deduct_minutes_cannot_exceed_remaining_balance(): void
    {
        [$contract, $actorId] = $this->activeContract(contractedMinutes: 600);

        $this->expectException(ContractRuleViolation::class);
        $this->expectExceptionMessage('SRV-004');

        ContractService::deductMinutes($contract, 601, $actorId);
    }

    public function test_contract_becomes_exceeded_when_fully_consumed(): void
    {
        [$contract, $actorId] = $this->activeContract(contractedMinutes: 600);

        ContractService::deductMinutes($contract, 600, $actorId);

        $this->assertSame(Contract::STATUS_EXCEEDED, $contract->fresh()->status);
        $this->assertSame(0, $contract->fresh()->remainingMinutes());
    }

    public function test_partial_deduction_stays_active(): void
    {
        [$contract, $actorId] = $this->activeContract(contractedMinutes: 600);

        ContractService::deductMinutes($contract, 300, $actorId);

        $this->assertSame(Contract::STATUS_ACTIVE, $contract->fresh()->status);
        $this->assertSame(300, $contract->fresh()->remainingMinutes());
    }

    // ---- SRV-014: pre-expiry check window --------------------------------

    public function test_needs_pre_expiry_check_within_30_days_of_expiry(): void
    {
        [$contract] = $this->activeContract();
        $contract->end_date = now()->addDays(10)->toDateString();
        $contract->save();

        $this->assertTrue(ContractService::needsPreExpiryCheck($contract->fresh()));
    }

    public function test_does_not_need_pre_expiry_check_beyond_30_days(): void
    {
        [$contract] = $this->activeContract();
        $contract->end_date = now()->addDays(31)->toDateString();
        $contract->save();

        $this->assertFalse(ContractService::needsPreExpiryCheck($contract->fresh()));
    }

    // ---- SRV-005: forfeit on expiry ----------------------------------

    public function test_expiring_a_contract_with_remaining_hours_records_them_as_forfeited(): void
    {
        [$contract, $actorId] = $this->activeContract(contractedMinutes: 600);
        ContractService::deductMinutes($contract, 200, $actorId);

        $record = ContractService::expireContract($contract->fresh(), $actorId);

        $this->assertNotNull($record);
        $this->assertSame(400, $record->expired_minutes);
        $this->assertSame(Contract::STATUS_EXPIRED, $contract->fresh()->status);
    }

    public function test_expiring_a_fully_consumed_contract_records_no_forfeit(): void
    {
        [$contract, $actorId] = $this->activeContract(contractedMinutes: 600);
        ContractService::deductMinutes($contract, 600, $actorId);

        $record = ContractService::expireContract($contract->fresh(), $actorId);

        $this->assertNull($record);
    }

    public function test_cannot_expire_a_draft_contract(): void
    {
        [$customer, $actorId] = $this->customerAndActor();
        $contract = ContractService::createContract(
            companyId: $customer->company_id, customerId: $customer->id,
            contractedHours: 10, contractValueSgd: 1000, startDate: '2026-01-01', actorUserId: $actorId,
        );

        $this->expectException(ContractRuleViolation::class);
        ContractService::expireContract($contract, $actorId);
    }

    // ---- SRV-016 / SRV-018: renewal backdating window --------------------

    public function test_renewal_within_two_weeks_of_expiry_is_eligible_for_backdating(): void
    {
        [$contract] = $this->activeContract();
        $contract->end_date = '2026-01-01';
        $contract->save();

        $eligibility = ContractService::checkRenewalEligibility($contract->fresh(), '2026-01-10');

        $this->assertTrue($eligibility['eligible_for_backdating']);
        $this->assertSame(9, $eligibility['days_since_expiry']);
    }

    public function test_renewal_beyond_two_weeks_is_not_eligible_for_backdating(): void
    {
        [$contract] = $this->activeContract();
        $contract->end_date = '2026-01-01';
        $contract->save();

        $eligibility = ContractService::checkRenewalEligibility($contract->fresh(), '2026-01-20');

        $this->assertFalse($eligibility['eligible_for_backdating']);
        $this->assertSame(19, $eligibility['days_since_expiry']);
    }

    public function test_renew_contract_backdates_seamlessly_within_window(): void
    {
        [$contract, $actorId] = $this->activeContract();
        $contract->end_date = now()->addDay()->toDateString(); // "expiring" tomorrow -- within window
        $contract->save();
        $contract->refresh();

        $newContract = ContractService::renewContract(
            priorContract: $contract, contractedHours: 10, contractValueSgd: 3000, actorUserId: $actorId,
        );

        $this->assertSame($contract->end_date->toDateString(), $newContract->start_date->toDateString());
        $this->assertSame($contract->id, $newContract->renewed_from_contract_id);
        $this->assertSame(Contract::STATUS_RENEWED, $contract->fresh()->status);
        $this->assertSame(Contract::STATUS_DRAFT, $newContract->status);
    }

    public function test_renew_beyond_window_requires_explicit_force_start_date(): void
    {
        [$contract, $actorId] = $this->activeContract();
        $contract->end_date = now()->subDays(30)->toDateString(); // expired a month ago
        $contract->save();
        $contract->refresh();

        $this->expectException(ContractRuleViolation::class);
        $this->expectExceptionMessage('SRV-018');

        ContractService::renewContract(priorContract: $contract, contractedHours: 10, contractValueSgd: 3000, actorUserId: $actorId);
    }

    public function test_renew_beyond_window_with_forced_start_date_succeeds(): void
    {
        [$contract, $actorId] = $this->activeContract();
        $contract->end_date = now()->subDays(30)->toDateString();
        $contract->save();
        $contract->refresh();

        $newContract = ContractService::renewContract(
            priorContract: $contract, contractedHours: 10, contractValueSgd: 3000,
            actorUserId: $actorId, forceStartDate: now()->toDateString(),
        );

        $this->assertSame(now()->toDateString(), $newContract->start_date->toDateString());
    }

    public function test_renewal_gives_a_fresh_allocation_not_the_prior_balance(): void
    {
        // SRV-005: a renewal never inherits the prior contract's
        // remaining/consumed balance -- it gets its own fresh pool.
        [$contract, $actorId] = $this->activeContract(contractedMinutes: 600);
        ContractService::deductMinutes($contract, 500, $actorId);
        $contract->end_date = now()->addDay()->toDateString();
        $contract->save();
        $contract->refresh();

        $newContract = ContractService::renewContract(
            priorContract: $contract, contractedHours: 10, contractValueSgd: 3000, actorUserId: $actorId,
        );

        $this->assertSame(0, $newContract->consumed_minutes);
        $this->assertSame(600, $newContract->contracted_minutes);
    }

    /** @return array{0: Contract, 1: string} [activated contract, actorUserId] */
    private function activeContract(int $contractedMinutes = 600): array
    {
        [$customer, $actorId] = $this->customerAndActor();
        $contract = ContractService::createContract(
            companyId: $customer->company_id, customerId: $customer->id,
            contractedHours: $contractedMinutes / 60, contractValueSgd: 3000,
            startDate: '2026-01-01', actorUserId: $actorId,
        );
        ContractService::activateContract($contract, $actorId);

        return [$contract, $actorId];
    }

    // ---- NEW FEATURES (not a Python->PHP conversion) -- see
    // docs/backlog.md / docs/planned-work.md -----------------------------

    public function test_allows_customer_true_for_the_contracts_own_customer(): void
    {
        [$contract] = $this->activeContract();

        $this->assertTrue($contract->allowsCustomer($contract->customer_id));
    }

    public function test_allows_customer_false_for_an_unrelated_customer(): void
    {
        [$contract] = $this->activeContract();
        $other = CompanyIndividual::factory()->for(Company::find($contract->company_id))->create();

        $this->assertFalse($contract->allowsCustomer($other->id));
    }

    public function test_allows_customer_true_once_added_to_the_shared_hours_list(): void
    {
        [$contract] = $this->activeContract();
        $shared = CompanyIndividual::factory()->for(Company::find($contract->company_id))->create();
        ContractSharedCustomer::create(['contract_id' => $contract->id, 'customer_id' => $shared->id]);

        $this->assertTrue($contract->allowsCustomer($shared->id));
    }

    public function test_due_for_renewal_uses_the_srv014_thirty_day_window(): void
    {
        [$contract] = $this->activeContract();
        $contract->end_date = now()->addDays(29)->toDateString();
        $contract->save();
        [$contractFar] = $this->activeContract();
        $contractFar->end_date = now()->addDays(45)->toDateString();
        $contractFar->save();

        $due = ContractService::dueForRenewal($contract->company_id);

        $ids = $due->pluck('id')->all();
        $this->assertContains($contract->id, $ids);
        $this->assertNotContains($contractFar->id, $ids);
    }

    public function test_expiry_listing_includes_expired_regardless_of_range_and_others_within_range(): void
    {
        [$customer, $actorId] = $this->customerAndActor();
        $companyId = $customer->company_id;
        $contract = ContractService::createContract(
            companyId: $companyId, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 3000, startDate: '2026-01-01', actorUserId: $actorId,
        );
        ContractService::activateContract($contract, $actorId);
        $contract->end_date = now()->addDays(10)->toDateString();
        $contract->save();

        $expiredContract = ContractService::createContract(
            companyId: $companyId, customerId: $customer->id, contractedHours: 10,
            contractValueSgd: 3000, startDate: '2020-01-01', actorUserId: $actorId,
        );
        ContractService::activateContract($expiredContract, $actorId);
        ContractService::expireContract($expiredContract, $actorId);
        $expiredContract->end_date = now()->subYears(2)->toDateString(); // well outside any default window
        $expiredContract->save();

        $rows = ContractService::expiryListing($companyId, now()->toDateString(), now()->addDays(30)->toDateString());

        $ids = $rows->pluck('id')->all();
        $this->assertContains($contract->id, $ids);
        $this->assertContains($expiredContract->id, $ids); // always included, per the OR rule
    }

    public function test_set_quotation_reference_rejected_while_contract_is_active(): void
    {
        [$contract, $actorId] = $this->activeContract();

        $this->expectException(ContractRuleViolation::class);
        ContractService::setQuotationReference($contract, 'QUO-2026-0001', $actorId);
    }

    public function test_set_quotation_reference_allowed_once_expired(): void
    {
        [$contract, $actorId] = $this->activeContract();
        ContractService::expireContract($contract, $actorId);

        ContractService::setQuotationReference($contract, 'QUO-2026-0001', $actorId);

        $this->assertSame('QUO-2026-0001', $contract->fresh()->quotation_reference);
        $this->assertNotNull($contract->fresh()->quotation_reference_set_at);
    }
}
