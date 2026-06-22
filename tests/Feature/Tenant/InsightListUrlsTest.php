<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\FundPostings\FundPostingResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Models\Central\Tenant;
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
        'name' => 'Insight List URL Admin',
        'email' => 'insight-list-urls@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');
});

test('contribution contributions list url uses filters query key for status', function () {
    $url = ContributionResource::listUrl('contributions', ['status' => ['value' => 'pending']]);

    expect($url)
        ->toContain('filters')
        ->toContain('status')
        ->not->toContain('tableFilters');
});

test('loan list url uses filters and includes tab when not default', function () {
    $portfolioUrl = LoanResource::listUrl('portfolio', ['status' => ['value' => 'active']]);

    expect($portfolioUrl)
        ->toContain('filters')
        ->not->toContain('tableFilters');

    $overdueUrl = LoanResource::listUrl('overdue_installments', ['status' => ['value' => 'overdue']]);

    expect($overdueUrl)
        ->toContain('tab=overdue_installments')
        ->toContain('filters')
        ->not->toContain('?tab=overdue_installments?');
});

test('loan queue url uses tab query parameter', function () {
    $url = LoanResource::queueUrl('ready_to_disburse');

    expect($url)
        ->toContain('tab=ready_to_disburse')
        ->not->toContain('?tab=ready_to_disburse?');
});

test('member delinquent tab url does not use tableFilters', function () {
    expect(MemberResource::listTabUrl('delinquent'))
        ->toContain('tab=delinquent')
        ->not->toContain('tableFilters');
});

test('fund posting member url uses filters', function () {
    $url = FundPostingResource::indexUrlForMember(7, 'pending');

    expect($url)
        ->toContain('filters')
        ->toContain('member_id')
        ->toContain('7')
        ->not->toContain('tableFilters');
});

test('bank accounts list url uses filters and includes tab when not default', function () {
    $postedUrl = BankAccountsResource::listUrl(
        BankClearingTabRegistry::TAB_HISTORY,
        ['status' => ['value' => 'posted']],
        historySection: BankClearingTabRegistry::HISTORY_CLOSED,
    );

    expect($postedUrl)
        ->toContain('filters')
        ->toContain('tab=history')
        ->not->toContain('tableFilters');

    $ledgerUrl = BankAccountsResource::listUrl('ledger', ['status' => ['value' => 'posted']]);

    expect($ledgerUrl)
        ->toContain('tab=ledger')
        ->toContain('filters');
});

test('membership application list tab url uses tab query parameter', function () {
    $url = MembershipApplicationResource::listTabUrl('pending');

    expect($url)
        ->toContain('tab=pending')
        ->not->toContain('tableFilters');
});
