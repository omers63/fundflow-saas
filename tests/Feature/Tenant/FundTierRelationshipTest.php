<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\Settings;
use App\Filament\Tenant\Resources\FundTiers\Pages\ListFundTiers;
use App\Filament\Tenant\Resources\LoanTiers\Pages\ListLoanTiers;
use App\Filament\Tenant\Widgets\FundTiersManageTableWidget;
use App\Filament\Tenant\Widgets\LoanTiersManageTableWidget;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Loans\LoanLifecycleService;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    app()->setLocale('en');
    Filament::setCurrentPanel('tenant');

    FundTier::query()->forceDelete();
    LoanTier::query()->forceDelete();

    $this->admin = User::create([
        'name' => 'Fund Tier Admin',
        'email' => 'fund-tier-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->actingAs($this->admin, 'tenant');
});

test('fund tier owns many loan tiers and a loan tier belongs to only one fund tier', function () {
    FundTier::create([
        'tier_number' => 0,
        'label' => 'Emergency',
        'percentage' => 0,
        'is_active' => true,
    ]);

    $fundA = FundTier::create([
        'tier_number' => FundTier::nextTierNumber(),
        'label' => 'Pool A',
        'percentage' => 40,
        'is_active' => true,
    ]);
    $fundB = FundTier::create([
        'tier_number' => FundTier::nextTierNumber(),
        'label' => 'Pool B',
        'percentage' => 40,
        'is_active' => true,
    ]);

    $loan1 = LoanTier::create([
        'tier_number' => 1,
        'label' => 'Band 1',
        'min_amount' => 0,
        'max_amount' => 10_000,
        'min_monthly_installment' => 500,
        'is_active' => true,
    ]);
    $loan2 = LoanTier::create([
        'tier_number' => 2,
        'label' => 'Band 2',
        'min_amount' => 10_001,
        'max_amount' => 25_000,
        'min_monthly_installment' => 1000,
        'is_active' => true,
    ]);

    $fundA->syncLoanTiers([$loan1->id, $loan2->id]);

    expect($loan1->fresh()->fund_tier_id)->toBe($fundA->id)
        ->and($loan2->fresh()->fund_tier_id)->toBe($fundA->id)
        ->and($fundA->fresh()->loanTiers)->toHaveCount(2);

    $fundB->syncLoanTiers([$loan2->id]);

    expect($loan2->fresh()->fund_tier_id)->toBe($fundA->id)
        ->and($fundB->fresh()->loanTiers)->toHaveCount(0);

    $fundA->syncLoanTiers([$loan1->id]);
    $fundB->syncLoanTiers([$loan2->id]);

    expect($loan1->fresh()->fund_tier_id)->toBe($fundA->id)
        ->and($loan2->fresh()->fund_tier_id)->toBe($fundB->id)
        ->and(FundTier::forLoanTier($loan1->id)?->id)->toBe($fundA->id)
        ->and(FundTier::forLoanTier($loan2->id)?->id)->toBe($fundB->id)
        ->and(FundTier::forLoanTier(999_999))->toBeNull();
});

test('next fund tier number skips emergency and increments', function () {
    FundTier::create([
        'tier_number' => 0,
        'label' => 'Emergency',
        'percentage' => 0,
        'is_active' => true,
    ]);

    expect(FundTier::nextTierNumber())->toBe(1);

    FundTier::create([
        'tier_number' => 1,
        'label' => 'Pool 1',
        'percentage' => 50,
        'is_active' => true,
    ]);

    expect(FundTier::nextTierNumber())->toBe(2);
});

test('list page creates and edits fund tiers via modals and toggles active', function () {
    FundTier::create([
        'tier_number' => 0,
        'label' => 'Emergency',
        'percentage' => 0,
        'is_active' => true,
    ]);

    $loan1 = LoanTier::create([
        'tier_number' => 1,
        'label' => 'Band 1',
        'min_amount' => 0,
        'max_amount' => 10_000,
        'min_monthly_installment' => 500,
        'is_active' => true,
    ]);
    $loan2 = LoanTier::create([
        'tier_number' => 2,
        'label' => 'Band 2',
        'min_amount' => 10_001,
        'max_amount' => 25_000,
        'min_monthly_installment' => 1000,
        'is_active' => true,
    ]);

    Livewire::test(ListFundTiers::class)
        ->assertSuccessful()
        ->callAction('create', [
            'label' => 'Growth pool',
            'percentage' => 35,
            'is_active' => true,
            'loan_tier_ids' => [$loan1->id, $loan2->id],
        ])
        ->assertHasNoActionErrors();

    $created = FundTier::query()->where('label', 'Growth pool')->first();

    expect($created)->not->toBeNull()
        ->and($created->tier_number)->toBe(1)
        ->and($loan1->fresh()->fund_tier_id)->toBe($created->id)
        ->and($loan2->fresh()->fund_tier_id)->toBe($created->id);

    Livewire::test(ListFundTiers::class)
        ->mountTableAction('edit', $created)
        ->setTableActionData([
            'label' => 'Growth pool updated',
            'percentage' => 40,
            'is_active' => true,
            'loan_tier_ids' => [$loan1->id],
        ])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($created->fresh()->label)->toBe('Growth pool updated')
        ->and((float) $created->fresh()->percentage)->toBe(40.0);

    // Detach via the same sync path the edit action uses (Livewire fillForm may merge multi-select state).
    $created->syncLoanTiers([$loan1->id]);

    expect($loan1->fresh()->fund_tier_id)->toBe($created->id)
        ->and($loan2->fresh()->fund_tier_id)->toBeNull();

    Livewire::test(ListFundTiers::class)
        ->call('updateTableColumnState', 'is_active', $created->getKey(), false)
        ->assertSuccessful();

    expect($created->fresh()->is_active)->toBeFalse();
});

test('settings fund tier summary lists linked loan tier labels', function () {
    $fund = FundTier::create([
        'tier_number' => 1,
        'label' => 'Ops pool',
        'percentage' => 25,
        'is_active' => true,
    ]);
    LoanTier::create([
        'tier_number' => 3,
        'label' => 'Mid band',
        'min_amount' => 1000,
        'max_amount' => 5000,
        'min_monthly_installment' => 500,
        'is_active' => true,
        'fund_tier_id' => $fund->id,
    ]);

    $rows = app(Settings::class)->getFundTierRows();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['label'])->toBe('Ops pool')
        ->and($rows[0]['loan_tier'])->toBe('Mid band')
        ->and($rows[0]['active'])->toBeTrue();
});

test('loan tier list creates via modal with auto tier number and optional fund pool', function () {
    $fund = FundTier::create([
        'tier_number' => 1,
        'label' => 'Pool',
        'percentage' => 50,
        'is_active' => true,
    ]);

    Livewire::test(ListLoanTiers::class)
        ->assertSuccessful()
        ->callAction('create', [
            'label' => 'New band',
            'min_amount' => 0,
            'max_amount' => 5000,
            'min_monthly_installment' => 500,
            'fund_tier_id' => $fund->id,
            'is_active' => true,
        ])
        ->assertHasNoActionErrors();

    $created = LoanTier::query()->where('label', 'New band')->first();

    expect($created)->not->toBeNull()
        ->and($created->tier_number)->toBe(LoanTier::query()->max('tier_number'))
        ->and($created->fund_tier_id)->toBe($fund->id)
        ->and(FundTier::forLoanTier($created->id)?->id)->toBe($fund->id);
});

test('fund and loan tier tables support delete and bulk delete', function () {
    $emergency = FundTier::create([
        'tier_number' => 0,
        'label' => 'Emergency',
        'percentage' => 0,
        'is_active' => true,
    ]);
    $fund = FundTier::create([
        'tier_number' => 1,
        'label' => 'Deletable pool',
        'percentage' => 40,
        'is_active' => true,
    ]);
    $loanA = LoanTier::create([
        'tier_number' => 1,
        'label' => 'Band A',
        'min_amount' => 0,
        'max_amount' => 5000,
        'min_monthly_installment' => 500,
        'is_active' => true,
        'fund_tier_id' => $fund->id,
    ]);
    $loanB = LoanTier::create([
        'tier_number' => 2,
        'label' => 'Band B',
        'min_amount' => 5001,
        'max_amount' => 10_000,
        'min_monthly_installment' => 750,
        'is_active' => true,
    ]);

    Livewire::test(ListFundTiers::class)
        ->assertSuccessful()
        ->assertTableActionVisible('delete', $fund)
        ->assertTableActionHidden('delete', $emergency)
        ->assertTableActionExists('delete')
        ->assertTableBulkActionExists('delete')
        ->callTableAction('delete', $fund)
        ->assertHasNoTableActionErrors();

    expect($fund->fresh()->trashed())->toBeTrue()
        ->and($loanA->fresh()->fund_tier_id)->toBeNull()
        ->and($emergency->fresh()->trashed())->toBeFalse();

    expect($emergency->delete())->toBeFalse()
        ->and($emergency->fresh()->trashed())->toBeFalse();

    Livewire::test(ListLoanTiers::class)
        ->assertSuccessful()
        ->assertTableActionExists('delete')
        ->assertTableBulkActionExists('delete')
        ->callTableAction('delete', $loanB)
        ->assertHasNoTableActionErrors()
        ->callTableBulkAction('delete', [$loanA]);

    expect($loanB->fresh()->trashed())->toBeTrue()
        ->and($loanA->fresh()->trashed())->toBeTrue();
});

test('fund and loan tier tables open edit modal on row click', function () {
    $fund = FundTier::create([
        'tier_number' => 1,
        'label' => 'Row click pool',
        'percentage' => 30,
        'is_active' => true,
    ]);
    $loan = LoanTier::create([
        'tier_number' => 1,
        'label' => 'Row click band',
        'min_amount' => 0,
        'max_amount' => 5000,
        'min_monthly_installment' => 500,
        'is_active' => true,
        'fund_tier_id' => $fund->id,
    ]);

    foreach ([
        [ListFundTiers::class, $fund],
        [ListLoanTiers::class, $loan],
        [FundTiersManageTableWidget::class, $fund],
        [LoanTiersManageTableWidget::class, $loan],
    ] as [$component, $record]) {
        $livewire = Livewire::test($component)->assertSuccessful();

        expect($livewire->instance()->getTable()->getRecordAction($record))
            ->toBe(EditAction::getDefaultName())
            ->and($livewire->instance()->getTable()->getRecordUrl($record))
            ->toBeNull()
            ->and($livewire->instance()->getTable()->hasAction('edit'))
            ->toBeTrue();

        $livewire
            ->mountTableAction('edit', (string) $record->getKey());

        expect($livewire->instance()->getMountedAction()?->getName())->toBe('edit');
    }
});

test('resolveForLoan uses linked fund pool from amount band when loan_tier_id is null', function () {
    $fund = FundTier::create([
        'tier_number' => 2,
        'label' => 'Growth',
        'percentage' => 40,
        'is_active' => true,
    ]);
    LoanTier::create([
        'tier_number' => 5,
        'label' => '5-10k',
        'min_amount' => 5000,
        'max_amount' => 10_000,
        'min_monthly_installment' => 1000,
        'is_active' => true,
        'fund_tier_id' => $fund->id,
    ]);

    $loan = Loan::query()->make([
        'amount_requested' => 7500,
        'is_emergency' => false,
        'loan_tier_id' => null,
        'fund_tier_id' => null,
    ]);

    expect(FundTier::resolveForLoan($loan)?->id)->toBe($fund->id);
});

test('approving a loan fails when its loan tier has no fund pool', function () {
    $member = Member::factory()->create(['status' => 'active']);
    app(AccountingService::class)->createMemberAccounts($member);

    LoanTier::create([
        'tier_number' => 20,
        'label' => 'Unlinked band',
        'min_amount' => 1000,
        'max_amount' => 20_000,
        'min_monthly_installment' => 1000,
        'is_active' => true,
        'fund_tier_id' => null,
    ]);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'pending',
        'amount_requested' => 5000,
        'amount' => 5000,
    ]);

    expect(fn () => app(LoanLifecycleService::class)->approveLoan($loan, 5000))
        ->toThrow(InvalidArgumentException::class);
});
