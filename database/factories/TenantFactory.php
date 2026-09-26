<?php

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        $name = fake()->company().' Hospital';

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numberBetween(1000, 999999),
            'type' => 'hospital',
            'email' => fake()->unique()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'county' => fake()->city(),
            'status' => 'active',
        ];
    }
}
