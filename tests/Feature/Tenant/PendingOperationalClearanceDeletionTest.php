<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\ExpenseDisbursement;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\FundPostingService;
use App\Services\MasterExpenseDisbursementService;
use App\Services\MemberCashOutService;
use App\Services\PendingOperationalClearanceDeletionService;
use App\Support\BankTransactionDeletion;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Notification::fake();

    Account::query()->delete();
    BankTransaction::query()->delete();
    FundPosting::query()->delete();
    CashOutRequest::query()->delete();
    ExpenseDisbursement::query()->delete();
    Transaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 50_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 50_000, 'is_master' => true]);
    Account::create(['type' => 'expense', 'name' => 'Master Expense', 'balance' => 0, 'is_master' => true]);
});

test('pending fund posting line can be deleted before accept without ledger impact', function () {
    $member = Member::create([
        'member_number' => 'MEM-PBM-01',
        'name' => 'Pending Deposit',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    $posting = app(FundPostingService::class)->submit($member, 900, now()->toDateString());
    $line = $posting->bankTransaction;

    expect(PendingOperationalClearanceDeletionService::canDelete($line))->toBeTrue()
        ->and(BankTransactionDeletion::canDelete($line))->toBeFalse();

    app(PendingOperationalClearanceDeletionService::class)->delete($line);

    expect(BankTransaction::query()->whereKey($line->id)->exists())->toBeFalse()
        ->and($posting->fresh()->status)->toBe('rejected')
        ->and($posting->fresh()->bank_transaction_id)->toBeNull()
        ->and(Transaction::query()->count())->toBe(0);
});

test('accepted fund posting pending bank match delete reverses ledger', function () {
    $member = Member::create([
        'member_number' => 'MEM-PBM-02',
        'name' => 'Accepted Deposit',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    $fundPostings = app(FundPostingService::class);
    $posting = $fundPostings->submit($member, 1_200, now()->toDateString());
    $fundPostings->accept($posting);

    $line = $posting->fresh()->bankTransaction;
    $member->refresh();

    expect((float) $member->cashAccount->balance)->toBe(1_200.0)
        ->and($line->is_cleared)->toBeFalse();

    $masterCashAfterAccept = (float) Account::masterCash()->balance;

    app(PendingOperationalClearanceDeletionService::class)->delete($line);

    $member->refresh();

    expect(BankTransaction::query()->whereKey($line->id)->exists())->toBeFalse()
        ->and($posting->fresh()->status)->toBe('rejected')
        ->and((float) $member->cashAccount->balance)->toBe(0.0)
        ->and((float) Account::masterCash()->balance)->toBe($masterCashAfterAccept);
});

test('accepted cash-out pending bank match delete reverses member and master cash', function () {
    $member = Member::create([
        'member_number' => 'MEM-PBM-03',
        'name' => 'Cash Out Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($member);
    $accounting->creditMemberCashWithMasterMirror(
        $member->cashAccount,
        3_000,
        'Seed',
        __('(seed mirror)'),
        null,
        null,
        $member->id,
    );
    $member->refresh();

    $cashOuts = app(MemberCashOutService::class);
    $request = $cashOuts->submit($member, 500);
    $masterCashBeforeAccept = (float) Account::masterCash()->balance;
    $cashOuts->accept($request, reviewedBy: null);

    $line = $request->fresh()->bankTransaction;

    app(PendingOperationalClearanceDeletionService::class)->delete($line);

    $member->refresh();

    expect(BankTransaction::query()->whereKey($line->id)->exists())->toBeFalse()
        ->and($request->fresh()->status)->toBe('rejected')
        ->and((float) $member->cashAccount->balance)->toBe(3_000.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe($masterCashBeforeAccept);
});

test('expense disbursement pending bank match delete reverses master expense only', function () {
    $masterExpense = Account::masterExpense();
    app(AccountingService::class)->fundReserveAccountFromMasterFund(
        $masterExpense,
        1_000,
        'Reserve',
    );

    $disbursement = app(MasterExpenseDisbursementService::class)->disburse(
        $masterExpense,
        250,
        'Vendor',
    );

    $line = $disbursement->bankTransaction;

    expect((float) $masterExpense->fresh()->balance)->toBe(750.0);

    app(PendingOperationalClearanceDeletionService::class)->delete($line);

    expect(BankTransaction::query()->whereKey($line->id)->exists())->toBeFalse()
        ->and(ExpenseDisbursement::query()->whereKey($disbursement->id)->exists())->toBeFalse()
        ->and((float) $masterExpense->fresh()->balance)->toBe(1_000.0)
        ->and((float) Account::masterCash()->balance)->toBe(50_000.0);
});

test('cleared operational lines cannot be deleted from pending bank match', function () {
    $member = Member::create([
        'member_number' => 'MEM-PBM-04',
        'name' => 'Cleared',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    $posting = app(FundPostingService::class)->submit($member, 100, now()->toDateString());
    $line = $posting->bankTransaction;
    $line->update(['is_cleared' => true]);

    expect(PendingOperationalClearanceDeletionService::canDelete($line))->toBeFalse();

    app(PendingOperationalClearanceDeletionService::class)->delete($line);
})->throws(InvalidArgumentException::class);

test('pending bank match table search does not query virtual clearance kind column', function () {
    $admin = User::create([
        'name' => 'Clearance Search Admin',
        'email' => 'clearance-search-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $masterExpense = Account::masterExpense();
    app(AccountingService::class)->fundReserveAccountFromMasterFund(
        $masterExpense,
        1_000,
        'Search test reserve',
    );

    app(MasterExpenseDisbursementService::class)->disburse(
        $masterExpense,
        250,
        'Office expense supplies',
    );

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($admin, 'tenant')
        ->test(ListBankAccounts::class, [
            'channel' => 'bank',
            'activeTab' => 'queue',
            'queueFilter' => 'operations',
        ])
        ->searchTable('expens')
        ->assertSuccessful();
});
