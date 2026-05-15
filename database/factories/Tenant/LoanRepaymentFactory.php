<?php

namespace Database\Factories\Tenant;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanRepayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<LoanRepayment> */
class LoanRepaymentFactory extends Factory
{
    protected $model = LoanRepayment::class;

    public function definition(): array
    {
        return [
            'loan_id' => Loan::factory(),
            'amount' => fake()->randomFloat(2, 500, 5000),
            'paid_at' => now(),
        ];
    }
}
