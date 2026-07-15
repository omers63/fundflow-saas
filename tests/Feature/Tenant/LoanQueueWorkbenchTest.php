<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\LoanQueueWorkbenchPage;
use App\Models\Central\Tenant;
use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
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
        'name' => 'Queue Admin',
        'email' => 'queue-workbench@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
        'preferred_locale' => 'en',
    ]);

    $this->actingAs($admin, 'tenant');
    app()->setLocale('en');

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100_000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->member = Member::create([
        'member_number' => 'MEM-WB01',
        'name' => 'Workbench Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->firstOrCreate(
        ['tier_number' => 0],
        ['label' => 'Loan Tier 0', 'min_amount' => 0, 'max_amount' => 50_000, 'min_monthly_installment' => 0, 'is_active' => true],
    );

    $this->fundTier = FundTier::query()->firstOrCreate(
        ['tier_number' => 1],
        ['label' => 'Fund Tier 1'],
    );
    $this->fundTier->update(['loan_tier_id' => $loanTier->id, 'percentage' => 100, 'is_active' => true]);
});

function makeWorkbenchLoan(Member $member, FundTier $fundTier, array $overrides = []): Loan
{
    return Loan::query()->create(array_merge([
        'member_id' => $member->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'pending',
        'is_emergency' => false,
        'applied_at' => now()->subDays(3),
        'loan_tier_id' => $fundTier->loan_tier_id,
    ], $overrides));
}

test('intake and process queues use separate column manager session keys', function () {
    $intakeKey = Livewire::test(LoanQueueWorkbenchPage::class)
        ->instance()
        ->getTableColumnsSessionKey();

    $processKey = Livewire::test(LoanQueueWorkbenchPage::class, ['queueTab' => 'process'])
        ->instance()
        ->getTableColumnsSessionKey();

    expect($intakeKey)->not->toBe($processKey);
});

test('switching queue tabs reloads the correct table columns', function () {
    makeWorkbenchLoan($this->member, $this->fundTier);

    $intakeColumns = [
        'is_emergency',
        'applied_at',
        'waiting_days',
        'member.name',
        'amount_requested',
        'loanTier.label',
        'expected_fund_tier',
        'projected_wait',
    ];

    $processColumns = [
        'queue_position',
        'member.name',
        'fundTier.label',
        'amount_requested',
        'amount_approved',
        'remaining_to_disburse',
        'coverage',
        'is_emergency',
        'status',
        'projected_wait',
    ];

    $component = Livewire::test(LoanQueueWorkbenchPage::class);

    foreach ($intakeColumns as $column) {
        $component->assertTableColumnVisible($column);
    }

    $component
        ->call('setQueueTab', 'process');

    foreach ($processColumns as $column) {
        $component->assertTableColumnVisible($column);
    }

    $component->call('setQueueTab', 'intake');

    foreach ($intakeColumns as $column) {
        $component->assertTableColumnVisible($column);
    }
});

test('workbench defaults to intake and normalizes legacy tab keys', function () {
    Livewire::test(LoanQueueWorkbenchPage::class)
        ->assertSuccessful()
        ->assertSet('queueTab', 'intake');

    Livewire::test(LoanQueueWorkbenchPage::class, ['queueTab' => 'ready_to_disburse'])
        ->assertSuccessful()
        ->assertSet('queueTab', 'process');

    Livewire::test(LoanQueueWorkbenchPage::class, ['queueTab' => 'needs_decision'])
        ->assertSet('queueTab', 'intake');
});

test('intake lists pending applications with emergencies pinned first', function () {
    $standard = makeWorkbenchLoan($this->member, $this->fundTier, [
        'applied_at' => now()->subDays(5),
    ]);
    $emergency = makeWorkbenchLoan($this->member, $this->fundTier, [
        'is_emergency' => true,
        'applied_at' => now()->subDay(),
    ]);

    Livewire::test(LoanQueueWorkbenchPage::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$emergency, $standard], inOrder: true);
});

test('triage actions toggle the emergency flag on pending applications', function () {
    $emergency = makeWorkbenchLoan($this->member, $this->fundTier, ['is_emergency' => true]);
    $standard = makeWorkbenchLoan($this->member, $this->fundTier);

    Livewire::test(LoanQueueWorkbenchPage::class)
        ->callTableAction('changeToStandard', $emergency)
        ->assertNotified();

    expect($emergency->fresh()->is_emergency)->toBeFalse();

    Livewire::test(LoanQueueWorkbenchPage::class)
        ->callTableAction('markAsEmergency', $standard)
        ->assertNotified();

    expect($standard->fresh()->is_emergency)->toBeTrue();
});

test('process queue surfaces fundable loans including partially disbursed with disburse action', function () {
    $approved = makeWorkbenchLoan($this->member, $this->fundTier, [
        'status' => 'approved',
        'amount_approved' => 10_000,
        'amount_disbursed' => 0,
        'approved_at' => now()->subDay(),
        'fund_tier_id' => $this->fundTier->id,
        'queue_position' => 1,
    ]);
    $partial = makeWorkbenchLoan($this->member, $this->fundTier, [
        'status' => 'partially_disbursed',
        'amount_approved' => 10_000,
        'amount_disbursed' => 4000,
        'approved_at' => now()->subDay(),
        'fund_tier_id' => $this->fundTier->id,
        'queue_position' => 2,
    ]);

    Livewire::test(LoanQueueWorkbenchPage::class)
        ->call('setQueueTab', 'process')
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$approved, $partial])
        ->assertTableActionVisible('disburse', $approved)
        ->assertTableActionVisible('disburse', $partial);
});

test('process queue lists loans waiting on pool when tier headroom is exhausted', function () {
    Loan::query()->create([
        'member_id' => $this->member->id,
        'amount' => 200_000,
        'amount_requested' => 200_000,
        'amount_approved' => 200_000,
        'amount_disbursed' => 200_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 16_667,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonths(3),
        'approved_at' => now()->subMonths(3),
        'disbursed_at' => now()->subMonths(3),
        'loan_tier_id' => $this->fundTier->loan_tier_id,
        'fund_tier_id' => $this->fundTier->id,
    ]);

    $waiting = makeWorkbenchLoan($this->member, $this->fundTier, [
        'status' => 'partially_disbursed',
        'amount_approved' => 20_000,
        'amount_disbursed' => 6_600,
        'approved_at' => now()->subDay(),
        'fund_tier_id' => $this->fundTier->id,
        'queue_position' => 1,
    ]);

    Livewire::test(LoanQueueWorkbenchPage::class)
        ->call('setQueueTab', 'process')
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$waiting])
        ->assertSee(__('Waiting on pool'));
});

test('process queue can sort by projected disbursement without sql errors', function () {
    $first = makeWorkbenchLoan($this->member, $this->fundTier, [
        'status' => 'approved',
        'amount_approved' => 10_000,
        'amount_disbursed' => 0,
        'approved_at' => now()->subDay(),
        'fund_tier_id' => $this->fundTier->id,
        'queue_position' => 2,
    ]);
    $second = makeWorkbenchLoan($this->member, $this->fundTier, [
        'status' => 'partially_disbursed',
        'amount_approved' => 10_000,
        'amount_disbursed' => 4_000,
        'approved_at' => now()->subDays(2),
        'fund_tier_id' => $this->fundTier->id,
        'queue_position' => 1,
    ]);

    Livewire::test(LoanQueueWorkbenchPage::class)
        ->call('setQueueTab', 'process')
        ->assertSuccessful()
        ->sortTable('projected_wait')
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$second, $first], inOrder: true);
});

test('tier queues tab renders pool figures, queued loans, and running loans with progress', function () {
    makeWorkbenchLoan($this->member, $this->fundTier, [
        'status' => 'approved',
        'amount_approved' => 10_000,
        'amount_disbursed' => 0,
        'approved_at' => now()->subDay(),
        'fund_tier_id' => $this->fundTier->id,
        'queue_position' => 1,
    ]);

    $runningMember = Member::create([
        'member_number' => 'MEM-WB02',
        'name' => 'Running Borrower',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $activeLoan = makeWorkbenchLoan($runningMember, $this->fundTier, [
        'status' => 'active',
        'amount_approved' => 10_000,
        'amount_disbursed' => 10_000,
        'approved_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
        'fund_tier_id' => $this->fundTier->id,
    ]);

    LoanInstallment::query()->create([
        'loan_id' => $activeLoan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->subMonth(),
        'status' => 'paid',
    ]);
    LoanInstallment::query()->create([
        'loan_id' => $activeLoan->id,
        'installment_number' => 2,
        'amount' => 1000,
        'due_date' => now()->addMonth(),
        'status' => 'pending',
    ]);

    Livewire::test(LoanQueueWorkbenchPage::class)
        ->call('setQueueTab', 'tiers')
        ->assertSuccessful()
        ->assertSee($this->fundTier->fresh()->label)
        ->assertSee($this->member->name)
        ->assertSee('Running Borrower')
        ->assertSee(__('Running — in repayment'))
        ->assertSeeHtml('bg-teal-500');
});
