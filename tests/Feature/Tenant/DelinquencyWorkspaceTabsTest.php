<?php

declare(strict_types=1);

use App\Filament\Support\LoanFilamentActions;
use App\Filament\Tenant\Pages\DelinquencyWorkspacePage;
use App\Filament\Tenant\Support\DelinquencyTabRegistry;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Delinquency Tabs Admin',
        'email' => 'delinquency-tabs-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');
});

it('switches delinquency side tabs via setSideTab', function () {
    Livewire::test(DelinquencyWorkspacePage::class)
        ->assertSet('sideTab', 'overview')
        ->call('setSideTab', 'overdue')
        ->assertSet('sideTab', 'overdue')
        ->assertSuccessful()
        ->call('setSideTab', 'guarantor')
        ->assertSet('sideTab', 'guarantor')
        ->assertSuccessful()
        ->call('setSideTab', 'policy')
        ->assertSet('sideTab', 'policy')
        ->assertSuccessful()
        ->call('setSideTab', 'related')
        ->assertSet('sideTab', 'related')
        ->assertSuccessful()
        ->call('setSideTab', 'overview')
        ->assertSet('sideTab', 'overview')
        ->assertSuccessful();
});

it('renders delinquency tab pills as navigable links', function () {
    $html = Livewire::test(DelinquencyWorkspacePage::class)->html();

    foreach (array_keys(DelinquencyTabRegistry::tabs()) as $tab) {
        expect($html)->toContain(DelinquencyTabRegistry::url($tab));
    }

    expect($html)
        ->toContain('ff-tenant-tab-pills__item no-underline')
        ->not->toContain('wire:click="setSideTab');
});

it('exposes admin loan transfer on overdue and guarantor liability on guarantor', function () {
    expect(collect(LoanFilamentActions::guarantorLiabilityActions())->map->getName()->all())
        ->toBe([
            'transferGuarantorLiability',
            'restoreBorrowerLiability',
        ]);

    Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => 'overdue'])
        ->assertSuccessful()
        ->assertTableActionExists('transferLoanAdmin');

    Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => 'guarantor'])
        ->assertSuccessful()
        ->assertTableActionExists('transferGuarantorLiability')
        ->assertTableActionExists('restoreBorrowerLiability')
        ->assertTableActionDoesNotExist('transferLoanAdmin');
});

it('defers delinquency insights until the section is unfolded', function () {
    Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => 'overview'])
        ->assertSuccessful()
        ->assertSee(__('Expand to load KPIs and risk summary.'))
        ->assertDontSee(__('Collections are current'))
        ->call('unfoldSection', 'insights')
        ->assertSee(__('Collections are current'));
});

it('paginates overdue installments without resetting to page one', function () {
    $accounting = app(AccountingService::class);

    $member = Member::create([
        'member_number' => 'MEM-DEL-PAGE',
        'name' => 'Pagination Member',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 10,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subYear(),
        'disbursed_at' => now()->subYear(),
    ]);

    $installments = collect(range(1, 12))->map(function (int $number) use ($loan) {
        return LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 1000,
            'due_date' => now()->subMonths(13 - $number)->startOfMonth(),
            'status' => 'overdue',
        ]);
    });

    $page = Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => 'overdue'])
        ->assertSuccessful()
        ->call('loadTable')
        ->assertCanSeeTableRecords($installments->take(10)->all())
        ->assertCanNotSeeTableRecords([$installments->last()]);

    $page->call('setPage', 2)
        ->assertSuccessful()
        ->assertSet('paginators.delinquency_overduePage', 2)
        ->assertCanSeeTableRecords([$installments->last()])
        ->assertCanNotSeeTableRecords([$installments->first()]);
});

it('uses a dedicated query string identifier for overdue pagination', function () {
    $component = Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => 'overdue']);

    expect($component->instance()->getTable()->getQueryStringIdentifier())->toBe('delinquency_overdue');
});

it('loads each delinquency panel from the sideTab query string', function () {
    foreach (DelinquencyTabRegistry::TABS as $tab) {
        Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => $tab])
            ->assertSet('sideTab', $tab)
            ->assertSuccessful();
    }
});
