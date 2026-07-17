<?php

declare(strict_types=1);

use App\Filament\Tables\Columns\Summarizers\LoanRemainingToDisburseSum;
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
use Filament\Tables\Columns\Summarizers\Sum;
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

    $this->loanTier = LoanTier::query()->firstOrCreate(
        ['tier_number' => 0],
        ['label' => 'Loan Tier 0', 'min_amount' => 0, 'max_amount' => 50_000, 'min_monthly_installment' => 0, 'is_active' => true],
    );

    $this->fundTier = FundTier::query()->firstOrCreate(
        ['tier_number' => 1],
        ['label' => 'Fund Tier 1'],
    );
    $this->fundTier->update(['percentage' => 100, 'is_active' => true]);
    $this->loanTier->update(['fund_tier_id' => $this->fundTier->id]);
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
        'loan_tier_id' => $fundTier->loanTiers()->value('id'),
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
        'loan_tier_id' => $this->loanTier->id,
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
        ->assertSee(__('Allocated'))
        ->assertSee(__('Committed'))
        ->assertSee(__('Headroom'))
        ->assertSee(__('Progress'))
        ->assertSee(__('Repaid'))
        ->assertSee(__('Outstanding'))
        ->assertSee(__(':percent% repaid', ['percent' => 50]))
        ->assertSeeHtml('ff-tier-heat')
        ->assertSeeHtml('ff-tier-heat__repay-fill')
        ->assertSeeHtml('bg-teal-500');
});

test('intake and process queue tables expose money column summary footers', function () {
    makeWorkbenchLoan($this->member, $this->fundTier, [
        'amount_requested' => 8_000,
    ]);
    makeWorkbenchLoan($this->member, $this->fundTier, [
        'status' => 'approved',
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 2_000,
        'approved_at' => now()->subDay(),
        'fund_tier_id' => $this->fundTier->id,
        'queue_position' => 1,
    ]);

    $intake = Livewire::test(LoanQueueWorkbenchPage::class)->assertSuccessful();
    $requested = collect($intake->instance()->getTable()->getColumns())
        ->first(fn ($column) => $column->getName() === 'amount_requested');

    expect($requested)->not->toBeNull()
        ->and($requested->getSummarizers())->toHaveCount(1)
        ->and($requested->getSummarizers()[0])->toBeInstanceOf(Sum::class)
        ->and($requested->getSummarizers()[0]->getLabel())->toBe(__('Requested'));

    $process = Livewire::test(LoanQueueWorkbenchPage::class, ['queueTab' => 'process'])
        ->assertSuccessful();

    $columns = collect($process->instance()->getTable()->getColumns());
    $approved = $columns->first(fn ($column) => $column->getName() === 'amount_approved');
    $remaining = $columns->first(fn ($column) => $column->getName() === 'remaining_to_disburse');

    expect($approved->getSummarizers())->toHaveCount(1)
        ->and($approved->getSummarizers()[0]->getLabel())->toBe(__('Approved'))
        ->and($remaining->getSummarizers())->toHaveCount(1)
        ->and($remaining->getSummarizers()[0])->toBeInstanceOf(LoanRemainingToDisburseSum::class)
        ->and($remaining->getSummarizers()[0]->getLabel())->toBe(__('Remaining'));

    $process->assertSee(__('Requested'))
        ->assertSee(__('Approved'))
        ->assertSee(__('Remaining'));
});

test('completed queue lists settled loans newest first and is view-only', function () {
    $older = makeWorkbenchLoan($this->member, $this->fundTier, [
        'status' => 'completed',
        'amount_approved' => 10_000,
        'amount_disbursed' => 10_000,
        'approved_at' => now()->subMonths(8),
        'disbursed_at' => now()->subMonths(8),
        'settled_at' => now()->subMonths(2),
        'fund_tier_id' => $this->fundTier->id,
    ]);
    $newer = makeWorkbenchLoan($this->member, $this->fundTier, [
        'status' => 'early_settled',
        'amount_approved' => 8_000,
        'amount_disbursed' => 8_000,
        'approved_at' => now()->subMonths(4),
        'disbursed_at' => now()->subMonths(4),
        'settled_at' => now()->subDays(3),
        'fund_tier_id' => $this->fundTier->id,
    ]);
    makeWorkbenchLoan($this->member, $this->fundTier, [
        'status' => 'active',
        'amount_approved' => 5_000,
        'amount_disbursed' => 5_000,
        'fund_tier_id' => $this->fundTier->id,
    ]);

    $component = Livewire::test(LoanQueueWorkbenchPage::class, ['queueTab' => 'completed'])
        ->assertSuccessful()
        ->assertSet('queueTab', 'completed')
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true)
        ->assertCanNotSeeTableRecords(
            Loan::query()->where('status', 'active')->get(),
        );

    $columns = collect($component->instance()->getTable()->getColumns())->map->getName()->all();

    expect($columns)->toContain('settled_at')
        ->and($columns)->toContain('fund_tier_label')
        ->and($columns)->not->toContain('projected_wait')
        ->and($component->instance()->getTable()->hasAction('approve'))->toBeFalse()
        ->and($component->instance()->getTable()->hasAction('disburse'))->toBeFalse()
        ->and($component->instance()->getTable()->hasAction('review'))->toBeTrue();
});

test('completed queue shows fund tier labels for soft-deleted pools', function () {
    $archived = FundTier::query()->create([
        'tier_number' => FundTier::nextTierNumber(),
        'label' => 'Archived pool',
        'percentage' => 15,
        'is_active' => true,
    ]);

    $loan = makeWorkbenchLoan($this->member, $this->fundTier, [
        'status' => 'completed',
        'amount_approved' => 7_000,
        'amount_disbursed' => 7_000,
        'approved_at' => now()->subYear(),
        'disbursed_at' => now()->subYear(),
        'settled_at' => now()->subMonth(),
        'fund_tier_id' => $archived->id,
    ]);

    $archived->delete();

    expect($archived->fresh()->trashed())->toBeTrue()
        ->and($loan->fresh()->fundTier)->toBeNull();

    Livewire::test(LoanQueueWorkbenchPage::class, ['queueTab' => 'completed'])
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$loan])
        ->assertSee('Archived pool')
        ->sortTable('fund_tier_label')
        ->assertSuccessful()
        ->assertCanSeeTableRecords([$loan]);
});

test('tier queues tab shows per-tier and all-tiers summary footers', function () {
    makeWorkbenchLoan($this->member, $this->fundTier, [
        'status' => 'approved',
        'amount_approved' => 10_000,
        'amount_disbursed' => 0,
        'approved_at' => now()->subDay(),
        'fund_tier_id' => $this->fundTier->id,
        'queue_position' => 1,
    ]);

    $component = Livewire::test(LoanQueueWorkbenchPage::class)
        ->call('setQueueTab', 'tiers')
        ->assertSuccessful()
        ->assertSee(__('Tier total'))
        ->assertSee(__('All tiers'));

    $summary = $component->instance()->getTierQueueSummary();

    expect($summary['queued_count'])->toBe(1)
        ->and($summary['queued_remaining'])->toBe(10_000.0)
        ->and($summary['running_count'])->toBe(0)
        ->and($summary['running_outstanding'])->toBe(0.0);
});
