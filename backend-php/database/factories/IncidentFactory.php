<?php

namespace Database\Factories;

use App\Models\Company;
use App\Models\Incident;
use App\Services\Numbering;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Incident>
 */
class IncidentFactory extends Factory
{
    protected $model = Incident::class;

    public function definition(): array
    {
        $company = Company::factory()->create();

        return [
            'company_id' => $company->id,
            'incident_number' => Numbering::next($company->id, 'incident'),
            'source' => Incident::SOURCE_PHONE,
            'subject' => fake()->sentence(4),
            'status' => Incident::STATUS_OPEN,
        ];
    }
}
