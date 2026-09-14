<?php

namespace Database\Factories;

use App\Models\CompanyIndividual;
use App\Models\Contract;
use App\Models\JobOrder;
use App\Models\ServiceRecord;
use App\Models\User;
use App\Services\Numbering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ServiceRecord>
 */
class ServiceRecordFactory extends Factory
{
    protected $model = ServiceRecord::class;

    public function definition(): array
    {
        $customer = CompanyIndividual::factory()->create();
        $contract = Contract::factory()->for($customer->company)->create(['customer_id' => $customer->id]);
        $jobOrder = JobOrder::factory()->for($customer->company)->create([
            'customer_id' => $customer->id, 'contract_id' => $contract->id,
        ]);
        $employee = User::factory()->for($customer->company)->create();

        return [
            'company_id' => $customer->company_id,
            'job_order_id' => $jobOrder->id,
            'employee_user_id' => $employee->id,
            'service_record_number' => Numbering::next($customer->company_id, 'service_record'),
            'work_date' => now()->toDateString(),
            'raw_minutes' => 60,
            'rounded_minutes' => 60,
            'status' => ServiceRecord::STATUS_SUBMITTED,
            'outcome' => ServiceRecord::OUTCOME_PENDING,
            'completion_status' => ServiceRecord::UNCOMPLETED,
        ];
    }
}
