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
