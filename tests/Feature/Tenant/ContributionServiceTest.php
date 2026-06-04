<?php

use App\Filament\Tenant\Resources\Contributions\Pages\ListContributions;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\ContributionService;
use App\Support\BusinessDaySettings;
use App\Support\ContributionCollectionStatus;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->accounting = app(AccountingService::class);
    $this->service = app(ContributionService::class);

    Account::query()->delete();
    Member::query()->delete();
    Setting::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
});

test('record contribution creates a pending contribution', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Test User',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $contribution = $this->service->recordContribution($member, '2026-05-01');

    expect($contribution->status)->toBe('pending');
    expect($contribution->amount)->toBe('5000.00');
    expect($contribution->member_id)->toBe($member->id);
});

test('posting contribution debits member cash and credits member fund and master fund', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Test User',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Account::masterCash()->update(['balance' => 5000]);
    $member->cashAccount->update(['balance' => 5000]);

    $contribution = $this->service->recordContribution($member, '2026-05-01');
    $contribution->update(['payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT]);
    $this->service->postContribution($contribution);

    $contribution->refresh();
    expect($contribution->status)->toBe('posted');
    expect($contribution->posted_at)->not->toBeNull();

    $member->refresh();
    expect($member->cashAccount->fresh()->balance)->toBe('0.00');
    expect($member->fundAccount->fresh()->balance)->toBe('5000.00');
    expect(Account::masterFund()->balance)->toBe('5000.00');
    expect(Account::masterCash()->balance)->toBe('0.00');
});

test('posting contribution uses configured business day for posted_at and ledger dates', function () {
    BusinessDaySettings::saveFromForm('2026-07-20');

    $member = Member::create([
        'member_number' => 'MEM-BDAY',
        'name' => 'Business Day Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Account::masterCash()->update(['balance' => 5000]);
    $member->cashAccount->update(['balance' => 5000]);

    $contribution = $this->service->recordContribution($member, '2026-07-01');
    $contribution->update(['payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT]);
    $this->service->postContribution($contribution);

    $contribution->refresh();

    expect($contribution->posted_at?->toDateString())->toBe('2026-07-20')
        ->and($contribution->paid_at?->toDateString())->toBe('2026-07-20');

    $ledgerDate = Transaction::query()
        ->where('reference_type', Contribution::class)
        ->where('reference_id', $contribution->id)
        ->where('account_id', $member->cashAccount->id)
        ->value('transacted_at');

    expect(Carbon::parse((string) $ledgerDate)->toDateString())->toBe('2026-07-20');
});

test('posting contribution tags master cash mirror with member name and id', function () {
    $member = Member::create([
        'member_number' => 'MEM-0042',
        'name' => 'Ada Lovelace',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Account::masterCash()->update(['balance' => 5000]);
    $member->cashAccount->update(['balance' => 5000]);

    $contribution = $this->service->recordContribution($member, '2026-05-01');
    $contribution->update(['payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT]);
    $this->service->postContribution($contribution);

    $masterCashDebit = Transaction::query()
        ->where('account_id', Account::masterCash()->id)
        ->where('type', 'debit')
        ->latest('id')
        ->first();

    expect($masterCashDebit)->not->toBeNull()
        ->and($masterCashDebit->member_id)->toBe($member->id)
        ->and($masterCashDebit->description)->toContain('Ada Lovelace')
        ->and($masterCashDebit->description)->not->toContain('contribution mirror');
});

test('contribution cycle uses configurable start day', function () {
    Setting::set('contribution', 'cycle_start_day', '10');

    expect($this->service->getCycleStartDay())->toBe(10);

    $range = $this->service->getCycleDateRange(now()->startOfMonth());
    expect($range['start']->day)->toBe(10);
});

test('default cycle start day is 6', function () {
    expect($this->service->getCycleStartDay())->toBe(6);
});

test('generate monthly contributions creates entries for all active members', function () {
    foreach (range(1, 3) as $i) {
        Member::create([
            'member_number' => "MEM-000{$i}",
            'name' => "Member {$i}",
            'monthly_contribution_amount' => 1000 * $i,
            'joined_at' => now()->subYear(),
            'status' => 'active',
        ]);
    }

    $count = $this->service->generateMonthlyContributions('2026-05-01');

    expect($count)->toBe(3);
    expect(Contribution::where('period', '2026-05-01')->count())->toBe(3);
});

test('delete contribution removes pending record without ledger impact', function () {
    $member = Member::create([
        'member_number' => 'MEM-DEL-01',
        'name' => 'Delete Pending',
        'monthly_contribution_amount' => 3000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $contribution = $this->service->recordContribution($member, '2026-04-01');

    $this->service->deleteContribution($contribution);

    expect(Contribution::query()->whereKey($contribution->id)->exists())->toBeFalse();
});

test('delete posted contribution reverses ledger and removes record', function () {
    $member = Member::create([
        'member_number' => 'MEM-DEL-02',
        'name' => 'Delete Posted',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Account::masterCash()->update(['balance' => 5000]);
    $member->cashAccount->update(['balance' => 5000]);

    $contribution = $this->service->recordContribution($member, '2026-05-01');
    $contribution->update(['payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT]);

    AccountingService::withoutMemberCashCollection(
        fn () => $this->service->postContribution($contribution),
    );

    expect(fn () => $this->service->deleteContribution($contribution->fresh()))
        ->toThrow(InvalidArgumentException::class);
});

test('delete posted manual admin contribution reverses ledger and removes record', function () {
    $member = Member::create([
        'member_number' => 'MEM-DEL-03',
        'name' => 'Delete Posted Admin',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Account::masterCash()->update(['balance' => 5000]);
    $member->cashAccount->update(['balance' => 5000]);

    $contribution = $this->service->recordContribution($member, '2026-05-01');

    AccountingService::withoutMemberCashCollection(
        fn () => $this->service->postContribution($contribution),
    );

    $this->service->deleteContribution($contribution->fresh());

    expect(Contribution::query()->whereKey($contribution->id)->exists())->toBeFalse()
        ->and($member->cashAccount->fresh()->balance)->toBe('5000.00')
        ->and($member->fundAccount->fresh()->balance)->toBe('0.00')
        ->and(Account::masterFund()->fresh()->balance)->toBe('0.00');
});

test('cycle generated contribution cannot be deleted', function () {
    $member = Member::create([
        'member_number' => 'MEM-DEL-04',
        'name' => 'Cycle Generated',
        'monthly_contribution_amount' => 3000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 3000,
        'amount_due' => 3000,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    expect($contribution->isSystemGenerated())->toBeTrue()
        ->and(fn () => $this->service->deleteContribution($contribution))
        ->toThrow(InvalidArgumentException::class);
});

test('generate monthly contributions creates cycle generated rows', function () {
    Member::create([
        'member_number' => 'MEM-GEN-01',
        'name' => 'Generated Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $count = $this->service->generateMonthlyContributions('2026-05-01');

    expect($count)->toBe(1);

    $contribution = Contribution::query()->where('period', '2026-05-01')->first();

    expect($contribution->payment_method)->toBe(Contribution::PAYMENT_METHOD_CASH_ACCOUNT)
        ->and($contribution->isSystemGenerated())->toBeTrue();
});

test('duplicate contributions are not generated', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Test User',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $this->service->generateMonthlyContributions('2026-05-01');
    $count = $this->service->generateMonthlyContributions('2026-05-01');

    expect($count)->toBe(0);
    expect(Contribution::where('member_id', $member->id)->where('period', '2026-05-01')->count())->toBe(1);
});

test('record contribution returns existing pending row for same period', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Test User',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $first = $this->service->recordContribution($member, '2026-05-01');
    $second = $this->service->recordContribution($member, '2026-05-01');

    expect($second->id)->toBe($first->id)
        ->and(Contribution::where('member_id', $member->id)->count())->toBe(1);
});

test('creating duplicate pending contribution throws validation exception', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Test User',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    Contribution::create([
        'member_id' => $member->id,
        'period' => '2026-05-01',
        'amount' => 5000,
        'amount_due' => 5000,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => 'pending',
        'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
    ]);

    expect(fn () => Contribution::create([
        'member_id' => $member->id,
        'period' => '2026-05-01',
        'amount' => 5000,
        'amount_due' => 5000,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => 'pending',
        'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
    ]))->toThrow(ValidationException::class);
});

test('soft deleted contribution blocks creating a replacement for the same period', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Test User',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $contribution = $this->service->recordContribution($member, '2026-05-01');
    $contribution->delete();

    expect(fn () => $this->service->recordContribution($member, '2026-05-01'))
        ->toThrow(ValidationException::class);
});

test('posting contribution with insufficient cash marks failed and throws', function () {
    $member = Member::create([
        'member_number' => 'MEM-INSUF',
        'name' => 'Low Cash',
        'monthly_contribution_amount' => 600,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $contribution = $this->service->recordContribution($member, '2026-05-01');
    $contribution->update(['payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT]);

    expect(fn () => $this->service->postContribution($contribution))
        ->toThrow(InvalidArgumentException::class);

    expect($contribution->fresh()->status)->toBe('failed');
});

test('ledger post action notifies instead of erroring when cash is insufficient', function () {
    Filament::setCurrentPanel('tenant');

    $admin = User::create([
        'name' => 'Post Admin',
        'email' => 'post-insufficient@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $member = Member::create([
        'member_number' => 'MEM-UI-INSUF',
        'name' => 'UI Low Cash',
        'monthly_contribution_amount' => 600,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $contribution = $this->service->recordContribution($member, '2026-05-01');
    $contribution->update(['payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT]);

    Livewire::actingAs($admin, 'tenant')
        ->test(ListContributions::class)
        ->set('activeTab', 'ledger')
        ->callTableAction('post', $contribution)
        ->assertSuccessful();

    expect($contribution->fresh()->status)->toBe('failed');

    Livewire::actingAs($admin, 'tenant')
        ->test(ListContributions::class)
        ->set('activeTab', 'ledger')
        ->assertTableActionVisible('post', $contribution);

    Account::masterCash()->update(['balance' => 600]);
    $member->cashAccount->update(['balance' => 600]);

    Livewire::actingAs($admin, 'tenant')
        ->test(ListContributions::class)
        ->set('activeTab', 'ledger')
        ->callTableAction('post', $contribution)
        ->assertSuccessful();

    expect($contribution->fresh()->status)->toBe('posted');
});
