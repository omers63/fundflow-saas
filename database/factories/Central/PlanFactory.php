<?php

namespace Database\Factories\Central;

use App\Models\Central\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

class PlanFactory extends Factory
{
    protected $model = Plan::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->word.' Plan',
            'slug' => $this->faker->slug,
            'price' => $this->faker->randomFloat(2, 10, 100),
            'currency' => 'USD',
            'billing_cycle' => 'monthly',
            'duration_months' => 1,
            'description' => $this->faker->sentence,
            'is_active' => true,
            'is_custom' => false,
        ];
    }
}
