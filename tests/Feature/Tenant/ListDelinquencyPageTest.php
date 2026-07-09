<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\LoanQueueWorkbenchPage;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Contributions\Pages\ListContributions;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Loans\Pages\ListLoans;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\Members\Pages\ViewMember;
use App\Models\Central\Tenant;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\Lang;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;
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
        'preferred_locale' => 'en',
    ]);

    $this->actingAs($admin, 'tenant');
    App::setLocale('en');
});

test('loan queue is a standalone sidebar page', function () {
    expect(LoanQueueWorkbenchPage::getNavigationLabel())->toBe(Lang::formatUiLabel(__('Loan queue')))
        ->and(LoanQueueWorkbenchPage::getUrl())->toContain('/admin/loan-queue');
});

test('loan queue kind filter narrows pending applications', function () {
    Livewire::test(LoanQueueWorkbenchPage::class)
        ->assertSuccessful()
        ->filterTable('queue_kind', 'emergency')
        ->assertSuccessful();
});

test('loans list defaults to collection tab', function () {
    Livewire::test(ListLoans::class)
        ->assertSet('activeTab', 'collection');
});

test('emi collection segment loads on loans list', function () {
    $path = parse_url(LoanResource::listTabUrl('emi_collect'), PHP_URL_PATH) ?? '/admin/loans';
    $query = parse_url(LoanResource::listTabUrl('emi_collect'), PHP_URL_QUERY);

    $this->get('http://'.$this->domain.$path.($query ? '?'.$query : ''))
        ->assertSuccessful()
        ->assertSee(__('Collection'), false)
        ->assertSee(__('To collect'), false);
});

test('loans list exposes delinquency maintenance actions on delinquency tab', function () {
    Livewire::test(ListLoans::class)
        ->set('activeTab', 'delinquency')
        ->set('delinquencyView', 'overdue')
        ->mountTableAction('markOverdueInstallments')
        ->callMountedTableAction()
        ->assertNotified();
});

test('overdue installments view loads on loans list', function () {
    $path = parse_url(LoanResource::listTabUrl('overdue_installments'), PHP_URL_PATH) ?? '/admin/loans';
    $query = parse_url(LoanResource::listTabUrl('overdue_installments'), PHP_URL_QUERY);

    $this->get('http://'.$this->domain.$path.($query ? '?'.$query : ''))
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

    $cycles = app(ContributionCycleService::class);
    [$month, $year] = $cycles->currentOpenPeriod();
    $rows = app(LoanDelinquencyService::class)->contributionArrearsTableRecords(
        $member->id,
        $month,
        $year,
        true,
    );
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

test('contribution arrears tab apply now posts from member cash', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $accounting = app(AccountingService::class);
    $member = Member::create([
        'member_number' => 'ARR-APPLY-'.uniqid(),
        'name' => 'Arrears Apply Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 5000]);

    $rows = app(LoanDelinquencyService::class)->contributionArrearsTableRecords($member->id);
    expect($rows)->not->toBeEmpty();

    $row = $rows->first();

    Livewire::test(ListContributions::class)
        ->set('activeTab', 'ledger')
        ->set('ledgerView', 'arrears')
        ->callTableAction('apply_single', (string) $row['__key'])
        ->assertNotified();

    expect(
        Contribution::query()
            ->where('member_id', $member->id)
            ->forPeriod((int) $row['month'], (int) $row['year'])
            ->where('status', 'posted')
            ->exists()
    )->toBeTrue();

    Carbon::setTestNow();
});

test('contribution arrears tab bulk apply now posts selected periods', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $accounting = app(AccountingService::class);
    $member = Member::create([
        'member_number' => 'ARR-BULK-'.uniqid(),
        'name' => 'Arrears Bulk Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 50000]);

    $rows = app(LoanDelinquencyService::class)->contributionArrearsTableRecords($member->id);
    expect($rows->count())->toBeGreaterThanOrEqual(2);

    $selected = $rows->take(2);
    $keys = $selected->pluck('__key')->map(fn ($key): string => (string) $key)->all();

    Livewire::test(ListContributions::class)
        ->set('activeTab', 'ledger')
        ->set('ledgerView', 'arrears')
        ->callTableBulkAction('applySelected', $keys)
        ->assertNotified();

    foreach ($selected as $row) {
        expect(
            Contribution::query()
                ->where('member_id', $member->id)
                ->forPeriod((int) $row['month'], (int) $row['year'])
                ->where('status', 'posted')
                ->exists()
        )->toBeTrue();
    }

    Carbon::setTestNow();
});

test('contribution arrears tab clear arrears waives period without posting cash', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $accounting = app(AccountingService::class);
    $member = Member::create([
        'member_number' => 'ARR-CLEAR-'.uniqid(),
        'name' => 'Arrears Clear Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 0]);

    $rows = app(LoanDelinquencyService::class)->contributionArrearsTableRecords($member->id);
    expect($rows)->not->toBeEmpty();

    $row = $rows->first();

    Livewire::test(ListContributions::class)
        ->set('activeTab', 'ledger')
        ->set('ledgerView', 'arrears')
        ->callTableAction('clear_arrears', (string) $row['__key'], data: ['note' => 'Board approved'])
        ->assertNotified();

    $contribution = Contribution::query()
        ->where('member_id', $member->id)
        ->forPeriod((int) $row['month'], (int) $row['year'])
        ->first();

    expect($contribution)->not->toBeNull()
        ->and($contribution->status)->toBe('waived')
        ->and((float) $contribution->amount_collected)->toBe(0.0)
        ->and($contribution->transactions()->count())->toBe(0);

    $remaining = app(LoanDelinquencyService::class)->contributionArrearsTableRecords($member->id);
    expect($remaining->contains(fn (array $r): bool => (int) $r['month'] === (int) $row['month']
        && (int) $r['year'] === (int) $row['year']))->toBeFalse();

    Carbon::setTestNow();
});

test('contribution arrears tab bulk clear arrears waives selected periods', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $accounting = app(AccountingService::class);
    $member = Member::create([
        'member_number' => 'ARR-BCLEAR-'.uniqid(),
        'name' => 'Arrears Bulk Clear Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

    $rows = app(LoanDelinquencyService::class)->contributionArrearsTableRecords($member->id);
    expect($rows->count())->toBeGreaterThanOrEqual(2);

    $selected = $rows->take(2);
    $keys = $selected->pluck('__key')->map(fn ($key): string => (string) $key)->all();

    Livewire::test(ListContributions::class)
        ->set('activeTab', 'ledger')
        ->set('ledgerView', 'arrears')
        ->callTableBulkAction('clearSelected', $keys)
        ->assertNotified();

    foreach ($selected as $row) {
        expect(
            Contribution::query()
                ->where('member_id', $member->id)
                ->forPeriod((int) $row['month'], (int) $row['year'])
                ->where('status', 'waived')
                ->exists()
        )->toBeTrue();
    }

    Carbon::setTestNow();
});

test('contribution arrears member filter scopes tab count to one member', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $accounting = app(AccountingService::class);

    $memberWithArrears = Member::create([
        'member_number' => 'ARR-SCOPE-1',
        'name' => 'Scoped Arrears Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($memberWithArrears);

    Member::create([
        'member_number' => 'ARR-SCOPE-2',
        'name' => 'Other Arrears Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);

    $delinquency = app(LoanDelinquencyService::class);
    $allCount = $delinquency->contributionArrearsTableRecords()->count();
    $scopedCount = $delinquency->contributionArrearsTableRecords($memberWithArrears->id)->count();

    expect($allCount)->toBeGreaterThan($scopedCount)
        ->and($scopedCount)->toBeGreaterThan(0)
        ->and(ContributionResource::contributionArrearsPeriodCount($memberWithArrears->id))->toBe($scopedCount);

    Carbon::setTestNow();
});

test('contribution arrears count matches table record count', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $delinquency = app(LoanDelinquencyService::class);

    expect($delinquency->countContributionArrearsPeriods())
        ->toBe($delinquency->contributionArrearsTableRecords()->count());

    Carbon::setTestNow();
});

test('delinquent members tab loads on members list', function () {
    $path = parse_url(MemberResource::listTabUrl('delinquent'), PHP_URL_PATH) ?? '/admin/members';

    $this->get('http://'.$this->domain.$path)
        ->assertSuccessful()
        ->assertSee(__('Arrears'), false);

    expect(LoanResource::listTabUrl('overdue_installments'))->toContain('tab=delinquency');
});

test('member workspace exposes arrears header actions', function () {
    $active = Member::create([
        'member_number' => 'DLQ-'.uniqid(),
        'name' => 'Active Arrears Member',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $held = Member::create([
        'member_number' => 'HLD-'.uniqid(),
        'name' => 'Held Row Member',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'inactive',
        'frozen_at' => null,
    ]);

    Livewire::test(ViewMember::class, ['record' => $active->getRouteKey()])
        ->assertActionVisible('checkMemberArrears')
        ->callAction('checkMemberArrears')
        ->assertNotified();

    Livewire::test(ViewMember::class, ['record' => $held->getRouteKey()])
        ->assertActionVisible('restoreSuspendedMember');
});
