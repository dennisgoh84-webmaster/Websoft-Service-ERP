<?php

namespace Database\Factories;

use App\Models\CompanyIndividual;
use App\Models\Payment;
use App\Services\Numbering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        $customer = CompanyIndividual::factory()->create();

        return [
            'company_id' => $customer->company_id,
            'customer_id' => $customer->id,
            'voucher_number' => Numbering::next($customer->company_id, 'receipt'),
            'payment_date' => now()->toDateString(),
            'amount_sgd' => 200,
        ];
    }
}
