<?php

namespace Database\Factories\Tenant;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Loan> */
class LoanFactory extends Factory
{
    protected $model = Loan::class;

    public function definition(): array
    {
        $amount = fake()->randomElement([10000, 25000, 50000, 100000]);
        $interestRate = fake()->randomElement([5, 10, 12, 15]);
        $termMonths = fake()->randomElement([6, 12, 18, 24]);
        $totalDue = $amount + ($amount * $interestRate / 100);
        $monthlyRepayment = round($totalDue / $termMonths, 2);

        return [
            'member_id' => Member::factory(),
            'amount' => $amount,
            'interest_rate' => $interestRate,
            'term_months' => $termMonths,
            'monthly_repayment' => $monthlyRepayment,
            'total_repaid' => 0,
            'status' => 'pending',
            'applied_at' => now(),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'approved',
            'approved_at' => now(),
        ]);
    }

    public function disbursed(): static
    {
        return $this->approved()->state(fn (array $attributes) => [
            'status' => 'disbursed',
            'disbursed_at' => now(),
        ]);
    }

    public function repaying(): static
    {
        return $this->disbursed()->state(fn (array $attributes) => [
            'status' => 'repaying',
        ]);
    }

    public function completed(): static
    {
        return $this->state(function (array $attributes) {
            $totalDue = $attributes['amount'] + ($attributes['amount'] * $attributes['interest_rate'] / 100);

            return [
                'status' => 'completed',
                'total_repaid' => $totalDue,
                'completed_at' => now(),
            ];
        });
    }
}
