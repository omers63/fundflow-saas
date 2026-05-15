<?php

namespace Database\Factories\Tenant;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Contribution> */
class ContributionFactory extends Factory
{
    protected $model = Contribution::class;

    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'period' => fake()->dateTimeBetween('-12 months', 'now')->format('Y-m-01'),
            'amount' => fake()->randomElement([500, 1000, 2000, 5000]),
            'status' => 'pending',
        ];
    }

    public function posted(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'posted',
            'posted_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
        ]);
    }

    public function forPeriod(string $period): static
    {
        return $this->state(fn (array $attributes) => [
            'period' => $period,
        ]);
    }
}
