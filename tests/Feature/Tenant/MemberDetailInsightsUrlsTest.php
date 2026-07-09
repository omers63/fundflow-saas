<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Central\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $tenant = Tenant::find('testing');

    if ($tenant !== null && ! $tenant->domains()->where('domain', 'testing.localhost')->exists()) {
        $tenant->domains()->create(['domain' => 'testing.localhost']);
    }

    $this->actingAs(User::create([
        'name' => 'Insights URL Admin',
        'email' => 'insights-urls@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');
});

test('contribution arrears url for member uses tab and filters query keys', function () {
    $url = ContributionResource::arrearsUrlForMember(42);

    expect($url)
        ->toContain('tab=ledger')
        ->toContain('view=arrears')
        ->toContain('filters')
        ->toContain('member_id')
        ->not->toContain('tableFilters')
        ->not->toContain('?tab=ledger?view=arrears');
});

test('overdue installments url for member uses tab and member filter', function () {
    $member = Member::factory()->create();

    $url = LoanResource::overdueInstallmentsUrlForMember($member);

    expect($url)
        ->toContain('tab=delinquency')
        ->toContain('filters')
        ->toContain('member_id')
        ->toContain((string) $member->id)
        ->not->toContain('tableFilters');
});
