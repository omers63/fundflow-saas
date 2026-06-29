<?php

namespace Database\Factories\Tenant;

use App\Models\Tenant\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Member> */
class MemberFactory extends Factory
{
    protected $model = Member::class;

    public function definition(): array
    {
        return [
            'member_number' => 'MEM-'.fake()->unique()->numerify('####'),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'phone' => fake()->phoneNumber(),
            'monthly_contribution_amount' => fake()->randomElement([500, 1000, 2000, 5000]),
            'joined_at' => fake()->dateTimeBetween('-3 years', 'now'),
            'status' => 'active',
            'contribution_cycles_active' => true,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
            'frozen_at' => null,
            'contribution_cycles_active' => false,
        ]);
    }

    public function withdrawn(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'withdrawn',
            'payout_frozen_at' => null,
        ]);
    }

    public function delinquent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'active',
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
            'frozen_at' => null,
            'contribution_cycles_active' => false,
        ]);
    }

    public function frozen(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
            'frozen_at' => now(),
            'contribution_cycles_active' => false,
        ]);
    }

    public function terminated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'withdrawn',
            'payout_frozen_at' => now(),
            'contribution_cycles_active' => false,
        ]);
    }

    public function withParent(Member $parent): static
    {
        return $this->state(fn (array $attributes) => [
            'parent_member_id' => $parent->id,
        ]);
    }

    public function longStanding(): static
    {
        return $this->state(fn (array $attributes) => [
            'joined_at' => fake()->dateTimeBetween('-3 years', '-13 months'),
        ]);
    }

    public function recent(): static
    {
        return $this->state(fn (array $attributes) => [
            'joined_at' => fake()->dateTimeBetween('-6 months', 'now'),
        ]);
    }
}
