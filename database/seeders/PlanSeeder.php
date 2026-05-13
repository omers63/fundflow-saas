<?php

namespace Database\Seeders;

use App\Models\Central\Plan;
use Illuminate\Database\Seeder;

class PlanSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $plans = [
            [
                'name' => 'Starter',
                'slug' => 'starter',
                'price' => 0,
                'currency' => 'IDR',
                'billing_cycle' => 'monthly',
                'duration_months' => 1,
                'description' => 'Perfect for individuals and small startups.',
                'data' => [
                    'max_locations' => 1,
                    'max_trainers' => 5,
                    'max_members' => 50,
                ],
                'is_active' => true,
            ],
            [
                'name' => 'Pro',
                'slug' => 'pro',
                'price' => 150000,
                'currency' => 'IDR',
                'billing_cycle' => 'monthly',
                'duration_months' => 1,
                'description' => 'For growing businesses that need more power.',
                'data' => [
                    'max_locations' => 3,
                    'max_trainers' => 15,
                    'max_members' => 200,
                    'has_custom_domain' => true,
                ],
                'is_active' => true,
            ],
            [
                'name' => 'Enterprise',
                'slug' => 'enterprise',
                'price' => 1500000, // Annual example
                'currency' => 'IDR',
                'billing_cycle' => 'annual',
                'duration_months' => 12,
                'description' => 'Unlimited possibilities for large organizations.',
                'data' => [
                    'max_locations' => 100,
                    'max_trainers' => 999,
                    'max_members' => 9999,
                    'has_custom_domain' => true,
                    'priority_support' => true,
                ],
                'is_active' => true,
            ],
        ];

        foreach ($plans as $plan) {
            Plan::firstOrCreate(['slug' => $plan['slug']], $plan);
        }
    }
}
