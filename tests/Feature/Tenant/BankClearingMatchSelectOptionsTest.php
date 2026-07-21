<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\BankClearingMatchService;
use App\Services\FundPostingService;
use App\Support\ContributionPolicySettings;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    Account::query()->delete();
    BankTransaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Match Select Admin',
        'email' => 'match-select-'.uniqid('', true).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->accounting = app(AccountingService::class);
});

test('match action select lists applicable imported csv lines', function () {
    $member = Member::create([
        'member_number' => 'MEM-MATCH-SEL',
        'name' => 'Match Select Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = app(FundPostingService::class)->submit($member, 9980, '2026-07-21');
    app(FundPostingService::class)->accept($posting);

    $operational = $posting->bankTransaction->fresh();

    $statement = BankStatement::create([
        'filename' => 'match-select-evidence.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2026-07-21',
        'description' => 'Member deposit inbound transfer',
        'amount' => 9980,
        'status' => 'imported',
        'hash' => md5('match-select-evidence'),
    ]);

    expect(app(BankClearingMatchService::class)->findImportedCandidates($operational)->pluck('id')->all())
        ->toContain($imported->id);

    $component = Livewire::actingAs($this->admin, 'tenant')
        ->withQueryParams(['queueFilter' => 'operations'])
        ->test(ListBankAccounts::class, [
            'activeTab' => 'queue',
            'queueFilter' => 'operations',
        ])
        ->mountTableAction('matchToBankLine', $operational);

    $form = $component->instance()->getMountedTableActionForm();
    expect($form)->not->toBeNull();

    /** @var Select|null $select */
    $select = collect($form->getFlatComponents(withHidden: true))
        ->first(fn ($component): bool => $component instanceof Select
            && $component->getName() === 'imported_transaction_id');

    expect($select)->not->toBeNull()
        ->and($select->getRecord()?->getKey())->toBe($operational->getKey())
        ->and($select->getOptions())->toHaveKey($imported->id);
});

test('match action select lists amount matches even when outside auto-match date window', function () {
    $member = Member::create([
        'member_number' => 'MEM-MATCH-DATE',
        'name' => 'Match Date Drift Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    // Operational date uses business/posting day; CSV evidence may be weeks apart.
    $posting = app(FundPostingService::class)->submit($member, 9980, '2025-11-05');
    app(FundPostingService::class)->accept($posting);
    $operational = $posting->bankTransaction->fresh();

    $statement = BankStatement::create([
        'filename' => 'date-drift-evidence.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2026-07-21',
        'description' => 'Member deposit inbound transfer',
        'amount' => 9980,
        'status' => 'imported',
        'hash' => md5('date-drift-evidence'),
    ]);

    $matching = app(BankClearingMatchService::class);

    expect($matching->findImportedCandidates($operational))->toHaveCount(0)
        ->and($matching->findManualImportedCandidates($operational)->pluck('id')->all())
        ->toContain($imported->id);

    ContributionPolicySettings::saveFromForm([
        ...ContributionPolicySettings::allForForm(),
        'collection_bank_match_manual_date_range_days' => 3,
    ]);

    expect($matching->findManualImportedCandidates($operational))->toHaveCount(0);

    ContributionPolicySettings::saveFromForm([
        ...ContributionPolicySettings::allForForm(),
        'collection_bank_match_manual_date_range_days' => 0,
    ]);

    $component = Livewire::actingAs($this->admin, 'tenant')
        ->withQueryParams(['queueFilter' => 'operations'])
        ->test(ListBankAccounts::class, [
            'activeTab' => 'queue',
            'queueFilter' => 'operations',
        ])
        ->mountTableAction('matchToBankLine', $operational);

    /** @var Select $select */
    $select = collect($component->instance()->getMountedTableActionForm()->getFlatComponents(withHidden: true))
        ->first(fn ($component): bool => $component instanceof Select
            && $component->getName() === 'imported_transaction_id');

    expect($select->getOptions())->toHaveKey($imported->id);
});
