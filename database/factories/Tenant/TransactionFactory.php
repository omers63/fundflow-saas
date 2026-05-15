<?php

namespace Database\Factories\Tenant;

use App\Models\Tenant\Account;
use App\Models\Tenant\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'type' => fake()->randomElement(['credit', 'debit']),
            'amount' => fake()->randomFloat(2, 100, 10000),
            'balance_after' => fake()->randomFloat(2, 0, 50000),
            'description' => fake()->sentence(),
            'transacted_at' => now(),
        ];
    }

    public function credit(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'credit',
        ]);
    }

    public function debit(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'debit',
        ]);
    }
}
