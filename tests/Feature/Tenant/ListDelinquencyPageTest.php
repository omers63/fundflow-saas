<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Central\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Loans\LoanDelinquencyService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    $admin = User::create([
        'name' => 'Delinquency Admin',
        'email' => 'delinquency-page@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');
});

test('contribution arrears tab loads without summary sql errors', function () {
    $path = parse_url(LoanResource::getUrl('delinquency'), PHP_URL_PATH) ?? '/admin/loans/delinquency';

    $this->get('http://'.$this->domain.$path.'?tab=contributions')
        ->assertSuccessful()
        ->assertSee(__('Contribution arrears'), false);
});

test('contribution arrears tab renders member and period columns', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $accounting = app(AccountingService::class);
    $member = Member::create([
        'member_number' => 'ARR-'.uniqid(),
        'name' => 'Arrears Table Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);
    $member = $member->fresh();

    $rows = app(LoanDelinquencyService::class)->contributionArrearsTableRecords($member->id);
    expect($rows)->not->toBeEmpty();

    $periodLabel = $rows->first()['period_label'];
    $path = parse_url(LoanResource::getUrl('delinquency'), PHP_URL_PATH) ?? '/admin/loans/delinquency';

    $this->get('http://'.$this->domain.$path.'?tab=contributions')
        ->assertSuccessful()
        ->assertSee('Arrears Table Member', false)
        ->assertSee($periodLabel, false);

    Carbon::setTestNow();
});
