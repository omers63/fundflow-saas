<?php

namespace Database\Factories\Tenant;

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Account> */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'member_id' => Member::factory(),
            'type' => fake()->randomElement(['cash', 'fund']),
            'name' => fake()->words(2, true).' Account',
            'balance' => 0,
            'is_master' => false,
        ];
    }

    public function cash(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'cash',
            'name' => 'Cash Account',
        ]);
    }

    public function fund(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'fund',
            'name' => 'Fund Account',
        ]);
    }

    public function master(): static
    {
        return $this->state(fn (array $attributes) => [
            'member_id' => null,
            'is_master' => true,
        ]);
    }

    public function masterCash(): static
    {
        return $this->master()->state(fn (array $attributes) => [
            'type' => 'cash',
            'name' => 'Master Cash',
        ]);
    }

    public function masterBank(): static
    {
        return $this->master()->state(fn (array $attributes) => [
            'type' => 'bank',
            'name' => 'Master Bank',
        ]);
    }

    public function masterFund(): static
    {
        return $this->master()->state(fn (array $attributes) => [
            'type' => 'fund',
            'name' => 'Master Fund',
        ]);
    }

    public function masterExpense(): static
    {
        return $this->master()->state(fn (array $attributes) => [
            'type' => 'expense',
            'name' => 'Master Expense',
        ]);
    }

    public function masterInvest(): static
    {
        return $this->master()->state(fn (array $attributes) => [
            'type' => 'invest',
            'name' => 'Master Invest',
        ]);
    }

    public function withBalance(float $amount): static
    {
        return $this->state(fn (array $attributes) => [
            'balance' => $amount,
        ]);
    }
}
