<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\ContributionCyclePage;
use App\Filament\Tenant\Pages\Dashboard;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\TenantDashboardService;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    $this->service = app(TenantDashboardService::class);

    Account::query()->delete();
    Member::query()->delete();
});

test('tenant dashboard snapshot includes greeting and workspace links', function () {
    $user = User::create([
        'name' => 'Fund Admin',
        'email' => 'admin@fund.test',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->actingAs($user, 'tenant');

    Member::create([
        'member_number' => 'MEM-001',
        'name' => 'Test Member',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 1000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 5000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 2000, 'is_master' => true]);

    $snapshot = $this->service->snapshot();

    expect($snapshot['greeting']['name'])->toBe('Fund Admin')
        ->and($snapshot['greeting']['fund_name'])->toBeString()->not->toBeEmpty()
        ->and($snapshot['quick_actions'])->toHaveCount(6)
        ->and($snapshot['gauges'])->toHaveCount(4)
        ->and($snapshot['balances'])->toHaveCount(3)
        ->and($snapshot['workspace_sections'])->not->toBeEmpty()
        ->and($snapshot['contribution_trend'])->toHaveCount(6)
        ->and(
            collect($snapshot['workspace_sections'])
                ->flatMap(fn (array $s): array => $s['links'])
                ->pluck('url')
                ->every(fn ($url): bool => is_string($url) && $url !== '')
        )->toBeTrue();
});

test('tenant dashboard resolves filament page urls', function () {
    Filament::setCurrentPanel('tenant');

    expect(Dashboard::getUrl())->toBeString()->not->toBeEmpty()
        ->and(ContributionCyclePage::getUrl())->toBeString()->not->toBeEmpty()
        ->and(LoanResource::getUrl('delinquency'))->toBeString()->not->toBeEmpty()
        ->and(MemberResource::getUrl('index'))->toBeString()->not->toBeEmpty();
});
