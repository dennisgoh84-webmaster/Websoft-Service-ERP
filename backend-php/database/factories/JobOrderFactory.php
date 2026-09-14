<?php

namespace Database\Factories;

use App\Models\CompanyIndividual;
use App\Models\JobOrder;
use App\Services\Numbering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<JobOrder>
 */
class JobOrderFactory extends Factory
{
    protected $model = JobOrder::class;

    public function definition(): array
    {
        $customer = CompanyIndividual::factory()->create();

        return [
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'job_order_number' => Numbering::next($customer->company_id, 'job_order'),
            'subject' => fake()->sentence(4),
            'job_order_type' => JobOrder::TYPE_SUPPORT,
            'priority' => JobOrder::PRIORITY_NORMAL,
            'status' => JobOrder::STATUS_OPEN,
        ];
    }
}
