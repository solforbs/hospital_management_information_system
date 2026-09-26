<?php

namespace Database\Factories;

use App\Models\Patient;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'mrn' => strtoupper(fake()->unique()->bothify('MRN-##-######')),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-90 years', '-1 years')->format('Y-m-d'),
            'sex' => fake()->randomElement(['male', 'female']),
            'phone' => fake()->phoneNumber(),
            'county' => fake()->city(),
            'category' => 'general',
            'registered_at' => now(),
        ];
    }
}
