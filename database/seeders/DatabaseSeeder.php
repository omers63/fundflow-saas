<?php

namespace Database\Seeders;

use App\Models\Family;
use App\Models\Member;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        if (Schema::hasTable('system_settings')) {
            SystemSetting::query()->firstOrCreate(
                ['id' => 1],
                [
                    'app_name' => 'FundFlow',
                    'support_email' => 'support@fundflow.test',
                    'public_hero_title' => 'Mobile-first family sponsorship',
                    'public_hero_subtitle' => 'Onboard, enroll, and manage families from one SaaS workspace.',
                ]
            );
        }

        if (!Schema::hasTable('families')) {
            return;
        }

        $family = Family::create([
            'name' => 'Al Hassan Family',
            'slug' => 'al-hassan',
            'family_code' => 'FAM-1001',
            'subscription_plan' => 'starter',
            'subscription_status' => 'trial',
        ]);

        $member = Member::create([
            'family_id' => $family->id,
            'full_name' => 'Aisha Al Hassan',
            'relation' => 'parent',
            'is_dependent' => false,
        ]);

        User::create([
            'name' => 'Family Admin',
            'email' => 'admin@family.test',
            'password' => 'password',
            'role' => 'admin',
            'family_id' => $family->id,
            'member_id' => $member->id,
        ]);

        User::create([
            'name' => 'Family Member',
            'email' => 'member@family.test',
            'password' => 'password',
            'role' => 'member',
            'family_id' => $family->id,
            'member_id' => $member->id,
        ]);
    }
}
