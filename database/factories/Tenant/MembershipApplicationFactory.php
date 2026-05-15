<?php

namespace Database\Factories\Tenant;

use App\Models\Tenant\MembershipApplication;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<MembershipApplication> */
class MembershipApplicationFactory extends Factory
{
    protected $model = MembershipApplication::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'password' => 'password',
            'phone' => fake()->phoneNumber(),
            'application_type' => fake()->randomElement(MembershipApplication::APPLICATION_TYPES),
            'message' => fake()->paragraph(),
            'status' => 'pending',
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'reviewed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'rejected',
            'reviewed_at' => now(),
        ]);
    }
}
