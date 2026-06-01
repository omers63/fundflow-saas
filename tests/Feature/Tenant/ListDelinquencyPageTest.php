<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Loans\Pages\ListLoans;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\Members\Pages\ListMembers;
use App\Models\Central\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Loans\LoanDelinquencyService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
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

test('loans list exposes delinquency maintenance actions', function () {
    Livewire::test(ListLoans::class)
        ->call('mountAction', 'markOverdueInstallments')
        ->callMountedAction()
        ->assertNotified();
});

test('overdue installments tab loads on loans list', function () {
    $path = parse_url(LoanResource::listTabUrl('overdue_installments'), PHP_URL_PATH) ?? '/admin/loans';

    $this->get('http://'.$this->domain.$path)
        ->assertSuccessful()
        ->assertSee(__('Overdue installments'), false);
});

test('contribution arrears tab loads without summary sql errors', function () {
    $url = ContributionResource::listTabUrl('arrears');
    $path = parse_url($url, PHP_URL_PATH) ?? '/admin/contributions';
    $query = parse_url($url, PHP_URL_QUERY);

    $this->get('http://'.$this->domain.$path.($query ? '?'.$query : ''))
        ->assertSuccessful()
        ->assertSee(__('Arrears'), false);
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

    $url = ContributionResource::listTabUrl('arrears');
    $path = parse_url($url, PHP_URL_PATH) ?? '/admin/contributions';
    $query = parse_url($url, PHP_URL_QUERY);

    $this->get('http://'.$this->domain.$path.($query ? '?'.$query : ''))
        ->assertSuccessful()
        ->assertSee('Arrears Table Member', false)
        ->assertSee($periodLabel, false);

    Carbon::setTestNow();
});

test('delinquent members tab loads on members list', function () {
    $path = parse_url(MemberResource::listTabUrl('delinquent'), PHP_URL_PATH) ?? '/admin/members';

    $this->get('http://'.$this->domain.$path)
        ->assertSuccessful()
        ->assertSee(__('Delinquent'), false);

    expect(LoanResource::listTabUrl('overdue_installments'))->toContain('tab=overdue_installments');
});

test('members list exposes delinquency row actions', function () {
    $member = Member::create([
        'member_number' => 'DLQ-'.uniqid(),
        'name' => 'Delinquent Row Member',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'delinquent',
    ]);

    Livewire::test(ListMembers::class)
        ->assertTableActionVisible('syncMemberDelinquency', $member)
        ->assertTableActionVisible('restoreMemberActive', $member)
        ->callTableAction('syncMemberDelinquency', $member)
        ->assertNotified();
});
