<?php

namespace Tests\Feature;

use App\Exceptions\IncidentRuleViolation;
use App\Models\Company;
use App\Models\CompanyIndividual;
use App\Models\Contact;
use App\Models\Contract;
use App\Models\Incident;
use App\Models\JobOrder;
use App\Models\User;
use App\Services\IncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unit-level coverage of App\Services\IncidentService, mirroring
 * backend/app/services/incidents.py function-for-function. See
 * docs/php-conversion-plan.md's "after converting each module"
 * checklist and docs/open-business-decisions.md #36 for the confirmed
 * rules being pinned here.
 */
class IncidentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_try_match_customer_by_email_is_case_insensitive(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        Contact::create(['customer_id' => $customer->id, 'name' => 'Jane', 'email' => 'Jane@Example.com']);

        $matched = IncidentService::tryMatchCustomerByEmail($company->id, 'jane@example.com');

        $this->assertSame($customer->id, $matched);
    }

    public function test_try_match_customer_by_email_returns_null_when_no_contact_matches(): void
    {
        $company = Company::factory()->create();

        $this->assertNull(IncidentService::tryMatchCustomerByEmail($company->id, 'nobody@example.com'));
        $this->assertNull(IncidentService::tryMatchCustomerByEmail($company->id, null));
    }

    public function test_try_match_customer_by_email_never_matches_another_companys_contact(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $customerB = CompanyIndividual::factory()->for($companyB)->create();
        Contact::create(['customer_id' => $customerB->id, 'name' => 'Jane', 'email' => 'jane@example.com']);

        $this->assertNull(IncidentService::tryMatchCustomerByEmail($companyA->id, 'jane@example.com'));
    }

    public function test_find_valid_contract_returns_most_recently_started_active_or_exceeded_contract(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        Contract::factory()->for($company)->create([
            'customer_id' => $customer->id, 'status' => Contract::STATUS_ACTIVE, 'start_date' => '2025-01-01',
        ]);
        $newer = Contract::factory()->for($company)->create([
            'customer_id' => $customer->id, 'status' => Contract::STATUS_EXCEEDED, 'start_date' => '2026-01-01',
        ]);
        Contract::factory()->for($company)->create([
            'customer_id' => $customer->id, 'status' => Contract::STATUS_DRAFT, 'start_date' => '2026-06-01',
        ]);

        $found = IncidentService::findValidContract($company->id, $customer->id);

        $this->assertSame($newer->id, $found->id);
    }

    public function test_find_valid_contract_returns_null_when_none_is_active_or_exceeded(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        Contract::factory()->for($company)->create(['customer_id' => $customer->id, 'status' => Contract::STATUS_DRAFT]);

        $this->assertNull(IncidentService::findValidContract($company->id, $customer->id));
    }

    public function test_create_incident_numbers_and_audits_it(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();

        $incident = IncidentService::createIncident(
            companyId: $company->id,
            customerId: null,
            source: Incident::SOURCE_PHONE,
            subject: 'Printer not working',
            description: null,
            senderName: 'Alice',
            senderEmail: null,
            senderPhone: '+65 1234 5678',
            createdByUserId: $user->id,
        );

        $this->assertStringStartsWith('INC-', $incident->incident_number);
        $this->assertSame(Incident::STATUS_OPEN, $incident->status);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'incident', 'entity_id' => $incident->id, 'action' => 'created',
        ]);
    }

    public function test_set_callback_requires_an_open_incident(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $incident = Incident::factory()->for($company)->create(['status' => Incident::STATUS_CLOSED]);

        $this->expectException(IncidentRuleViolation::class);
        IncidentService::setCallback($incident, $user->id, $user->id);
    }

    public function test_set_callback_marks_pending_callback_with_assignee(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $incident = Incident::factory()->for($company)->create();

        IncidentService::setCallback($incident, $user->id, $user->id);

        $this->assertSame(Incident::STATUS_PENDING_CALLBACK, $incident->status);
        $this->assertSame($user->id, $incident->assigned_to_user_id);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'incident', 'entity_id' => $incident->id, 'action' => 'pending_callback',
        ]);
    }

    public function test_close_incident_sets_reason_and_timestamp(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $incident = Incident::factory()->for($company)->create();

        IncidentService::closeIncident($incident, 'Resolved over the phone', $user->id);

        $this->assertSame(Incident::STATUS_CLOSED, $incident->status);
        $this->assertSame('Resolved over the phone', $incident->close_reason);
        $this->assertNotNull($incident->closed_at);
        $this->assertSame($user->id, $incident->closed_by_user_id);
    }

    public function test_close_an_already_closed_incident_is_rejected(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $incident = Incident::factory()->for($company)->create(['status' => Incident::STATUS_CLOSED]);

        $this->expectException(IncidentRuleViolation::class);
        IncidentService::closeIncident($incident, 'again', $user->id);
    }

    public function test_close_a_converted_incident_is_rejected(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $incident = Incident::factory()->for($company)->create(['status' => Incident::STATUS_CONVERTED]);

        $this->expectException(IncidentRuleViolation::class);
        IncidentService::closeIncident($incident, 'x', $user->id);
    }

    public function test_convert_to_quotation_requires_a_customer(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $incident = Incident::factory()->for($company)->create(['customer_id' => null]);

        $this->expectException(IncidentRuleViolation::class);
        IncidentService::convertToQuotation($incident, '2026-09-14', $user->id);
    }

    public function test_convert_to_quotation_creates_a_draft_quotation_with_one_placeholder_line(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $user = User::factory()->for($company)->create();
        $incident = Incident::factory()->for($company)->create([
            'customer_id' => $customer->id, 'subject' => 'Need a new server quoted',
        ]);

        $quotation = IncidentService::convertToQuotation($incident, '2026-09-14', $user->id);

        $this->assertSame(Incident::STATUS_CONVERTED, $incident->status);
        $this->assertSame($quotation->id, $incident->converted_quotation_id);
        $quotation->load('lines');
        $this->assertCount(1, $quotation->lines);
        $this->assertSame('Need a new server quoted', $quotation->lines->first()->description);
        $this->assertSame('0.00', $quotation->lines->first()->unit_price_sgd);
        $this->assertStringContainsString($incident->incident_number, $quotation->notes);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'incident', 'entity_id' => $incident->id, 'action' => 'converted_to_quotation',
        ]);
    }

    public function test_convert_to_quotation_twice_is_rejected_since_incident_is_no_longer_open(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $user = User::factory()->for($company)->create();
        $incident = Incident::factory()->for($company)->create(['customer_id' => $customer->id]);
        IncidentService::convertToQuotation($incident, '2026-09-14', $user->id);

        $this->expectException(IncidentRuleViolation::class);
        IncidentService::convertToQuotation($incident, '2026-09-14', $user->id);
    }

    public function test_convert_to_job_order_requires_a_customer(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->for($company)->create();
        $incident = Incident::factory()->for($company)->create(['customer_id' => null]);
        $contract = Contract::factory()->for($company)->create(['status' => Contract::STATUS_ACTIVE]);

        $this->expectException(IncidentRuleViolation::class);
        IncidentService::convertToJobOrder($incident, $contract->id, JobOrder::PRIORITY_NORMAL, $user->id);
    }

    public function test_convert_to_job_order_rejects_a_contract_belonging_to_a_different_customer(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $otherCustomer = CompanyIndividual::factory()->for($company)->create();
        $user = User::factory()->for($company)->create();
        $incident = Incident::factory()->for($company)->create(['customer_id' => $customer->id]);
        $contract = Contract::factory()->for($company)->create([
            'customer_id' => $otherCustomer->id, 'status' => Contract::STATUS_ACTIVE,
        ]);

        $this->expectException(IncidentRuleViolation::class);
        IncidentService::convertToJobOrder($incident, $contract->id, JobOrder::PRIORITY_NORMAL, $user->id);
    }

    public function test_convert_to_job_order_rejects_an_expired_contract(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $user = User::factory()->for($company)->create();
        $incident = Incident::factory()->for($company)->create(['customer_id' => $customer->id]);
        $contract = Contract::factory()->for($company)->create([
            'customer_id' => $customer->id, 'status' => Contract::STATUS_EXPIRED,
        ]);

        $this->expectException(IncidentRuleViolation::class);
        IncidentService::convertToJobOrder($incident, $contract->id, JobOrder::PRIORITY_NORMAL, $user->id);
    }

    public function test_convert_to_job_order_accepts_an_exceeded_contract(): void
    {
        $company = Company::factory()->create();
        $customer = CompanyIndividual::factory()->for($company)->create();
        $user = User::factory()->for($company)->create();
        $incident = Incident::factory()->for($company)->create([
            'customer_id' => $customer->id, 'subject' => 'Server down',
        ]);
        $contract = Contract::factory()->for($company)->create([
            'customer_id' => $customer->id, 'status' => Contract::STATUS_EXCEEDED,
        ]);

        $jobOrder = IncidentService::convertToJobOrder($incident, $contract->id, JobOrder::PRIORITY_HIGH, $user->id);

        $this->assertSame(Incident::STATUS_CONVERTED, $incident->status);
        $this->assertSame($jobOrder->id, $incident->converted_job_order_id);
        $this->assertSame($contract->id, $jobOrder->contract_id);
        $this->assertSame('Server down', $jobOrder->subject);
        $this->assertSame(JobOrder::PRIORITY_HIGH, $jobOrder->priority);
        // Defaults to SUPPORT type (no milestones), never PROJECT --
        // matches JobOrderType.SUPPORT being the SQLAlchemy default in
        // backend/app/models/job_orders.py, which the Python function
        // never overrides either.
        $this->assertSame(JobOrder::TYPE_SUPPORT, $jobOrder->job_order_type);
        $this->assertStringStartsWith('JO-', $jobOrder->job_order_number);
        $this->assertDatabaseHas('audit_log_entries', [
            'entity_type' => 'incident', 'entity_id' => $incident->id, 'action' => 'converted_to_job_order',
        ]);
    }
}
