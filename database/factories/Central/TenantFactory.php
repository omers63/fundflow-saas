<?php

namespace Database\Factories\Central;

use App\Models\Central\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'id' => $this->faker->unique()->slug(),
            'name' => $this->faker->company(),
        ];
    }

    /**
     * Configure the model factory.
     *
     * @return $this
     */
    public function configure()
    {
        return $this->afterCreating(function (Tenant $tenant) {
            $tenant->domains()->create([
                'domain' => $tenant->id.'.'.config('tenancy.central_domain'),
            ]);
        });
    }
}
