<?php

namespace Database\Factories\Central;

use App\Models\Central\Invoice;
use App\Models\Central\Plan;
use App\Models\Central\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    public function definition(): array
    {
        return [
            'invoice_number' => 'INV-'.$this->faker->unique()->numberBetween(1000, 9999),
            'tenant_id' => Tenant::factory(),
            'plan_id' => Plan::factory(),
            'amount' => $this->faker->randomFloat(2, 10, 100),
            'currency' => 'USD',
            'status' => 'pending',
            'description' => $this->faker->sentence,
        ];
    }
}
