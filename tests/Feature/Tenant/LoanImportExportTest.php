<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Loans\Pages\ListLoans;
use App\Models\Central\Tenant;
use App\Models\Tenant\Account;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Loans\LoanExportService;
use App\Services\Loans\LoanImportService;
use App\Services\Loans\LoanRepaymentExportService;
use App\Services\Loans\LoanRepaymentImportService;
use App\Services\MemberCashOutService;
use App\Services\MemberImportService;
use App\Support\AssociativeCsv;
use App\Support\LegacyMigrationSampleCsv;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
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

    $this->admin = User::create([
        'name' => 'Loan Import Admin',
        'email' => 'loan-import@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($this->admin, 'tenant');

    Account::query()->delete();
    Loan::query()->delete();
    FundTier::query()->forceDelete();
    LoanTier::query()->forceDelete();
    Member::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 500_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 500_000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $nextTierNumber = max(1, (int) LoanTier::withTrashed()->max('tier_number') + 1);
    if ($nextTierNumber > 250) {
        $nextTierNumber = 1;
    }

    $this->loanTier = LoanTier::create([
        'tier_number' => $nextTierNumber,
        'label' => 'Standard',
        'min_amount' => 1000,
        'max_amount' => 100_000,
        'min_monthly_installment' => 1000,
        'is_active' => true,
    ]);

    $this->fundTier = FundTier::create([
        'tier_number' => min(254, $nextTierNumber + 1),
        'label' => 'Pool A',
        'loan_tier_id' => $this->loanTier->id,
        'percentage' => 25,
        'is_active' => true,
    ]);

    FundTier::create([
        'tier_number' => 0,
        'label' => 'Emergency',
        'loan_tier_id' => null,
        'percentage' => 0,
        'is_active' => true,
    ]);

    $this->accounting = app(AccountingService::class);
});

function createLoanImportMember(AccountingService $accounting, string $email, float $fundBalance = 20_000): Member
{
    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Import Member '.substr($email, 0, 8),
        'email' => $email,
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);
    $member->fundAccount()->update(['balance' => $fundBalance]);
    $member->cashAccount()->update(['balance' => 0]);

    return $member->fresh();
}

function writeLoanImportCsv(string $contents): string
{
    $path = sys_get_temp_dir().'/loan-import-'.uniqid('', true).'.csv';
    file_put_contents($path, $contents);

    return $path;
}

test('loan import preserves legacy loan_id from csv when provided', function () {
    $member = createLoanImportMember($this->accounting, 'legacy-loan-id-import@example.test');
    $legacyLoanId = 77_881;

    $csv = <<<CSV
loan_status,member_email,amount_approved,member_portion,master_portion,disbursed_at,installments_count,loan_id
active,{$member->email},10000,4000,6000,2024-06-01,10,{$legacyLoanId}
CSV;

    $path = writeLoanImportCsv($csv);
    $result = app(LoanImportService::class)->import($path);

    expect($result)->toMatchArray(['created' => 1, 'failed' => 0]);

    $loan = Loan::query()->find($legacyLoanId);

    expect($loan)->not->toBeNull()
        ->and($loan->member_id)->toBe($member->id)
        ->and($loan->disbursed_at?->toDateString())->toBe('2024-06-01');

    @unlink($path);
});

test('loan import with multiple legacy loan ids completes without transaction errors', function () {
    $firstMember = createLoanImportMember($this->accounting, 'legacy-batch-a@example.test');
    $secondMember = createLoanImportMember($this->accounting, 'legacy-batch-b@example.test');

    $csv = <<<CSV
loan_status,member_email,amount_approved,member_portion,master_portion,disbursed_at,installments_count,loan_id
active,{$firstMember->email},10000,4000,6000,2024-06-01,10,94
active,{$secondMember->email},12000,5000,7000,2024-07-01,12,95
CSV;

    $path = writeLoanImportCsv($csv);
    $result = app(LoanImportService::class)->import($path);

    expect($result)->toMatchArray(['created' => 2, 'failed' => 0])
        ->and(Loan::query()->find(94))->not->toBeNull()
        ->and(Loan::query()->find(95))->not->toBeNull();

    @unlink($path);
});

test('loan import creates pending loan from csv row', function () {
    $member = createLoanImportMember($this->accounting, 'pending-import@example.test');

    $csv = <<<CSV
loan_status,member_email,amount_requested,purpose
pending,{$member->email},15000,Test pending import
CSV;

    $path = writeLoanImportCsv($csv);
    $result = app(LoanImportService::class)->import($path);

    expect($result['created'])->toBe(1)
        ->and($result['failed'])->toBe(0)
        ->and($result['errors'])->toBeEmpty();

    $loan = Loan::query()->where('member_id', $member->id)->first();

    expect($loan)->not->toBeNull()
        ->and($loan->status)->toBe('pending')
        ->and((float) $loan->amount_requested)->toBe(15000.0)
        ->and($loan->purpose)->toBe('Test pending import');
});

test('loan import creates active disbursed loan with ledger postings', function () {
    $member = createLoanImportMember($this->accounting, 'active-import@example.test', 25_000);

    $csv = <<<CSV
loan_status,member_email,amount_approved,member_portion,master_portion,disbursed_at,paid_installments_count
active,{$member->email},10000,4000,6000,2024-06-01,2
CSV;

    $path = writeLoanImportCsv($csv);
    $result = app(LoanImportService::class)->import($path);

    expect($result)->toMatchArray(['created' => 1, 'failed' => 0]);

    $loan = Loan::query()->where('member_id', $member->id)->firstOrFail();

    expect($loan->status)->toBe('active')
        ->and((float) $loan->amount_approved)->toBe(10000.0)
        ->and((float) $loan->member_portion)->toBe(4000.0)
        ->and((float) $loan->master_portion)->toBe(6000.0)
        ->and($loan->installments()->where('status', 'paid')->count())->toBe(2)
        ->and($loan->disbursements()->count())->toBe(1);

    $cashOut = CashOutRequest::query()->where('member_id', $member->id)->firstOrFail();

    expect($cashOut->status)->toBe('accepted')
        ->and((float) $cashOut->amount)->toBe(10000.0)
        ->and($cashOut->bank_transaction_id)->not->toBeNull()
        ->and($cashOut->reviewed_at?->toDateString())->toBe('2024-06-01');

    $member->refresh()->load('cashAccount', 'fundAccount');
    expect((float) $member->cashAccount->fresh()->balance)->toBe(0.0);
});

test('loan import defaults disbursed loans to full member portion when portions are omitted', function () {
    $member = createLoanImportMember($this->accounting, 'active-default-portions@example.test', 0);

    $csv = <<<CSV
loan_status,member_email,amount_approved,disbursed_at,paid_installments_count
active,{$member->email},10000,2024-06-01,0
CSV;

    $path = writeLoanImportCsv($csv);
    $result = app(LoanImportService::class)->import($path);

    expect($result)->toMatchArray(['created' => 1, 'failed' => 0]);

    $loan = Loan::query()->where('member_id', $member->id)->latest('id')->firstOrFail();
    $member->refresh()->load('fundAccount', 'cashAccount');
    $masterFund = Account::masterFund();

    expect((float) $loan->member_portion)->toBe(10000.0)
        ->and((float) $loan->master_portion)->toBe(0.0)
        ->and((float) $member->fundAccount->balance)->toBe(-10000.0)
        ->and((float) $masterFund->fresh()->balance)->toBe(490_000.0)
        ->and((float) $member->cashAccount->balance)->toBe(0.0)
        ->and($loan->installments()->count())->toBe(7);
});

test('loan import completes fully member-funded loans with zero settlement threshold', function () {
    $member = createLoanImportMember($this->accounting, 'fund-only-zero-settlement@example.test', 10_000);

    $csv = <<<CSV
loan_status,member_email,amount_approved,member_portion,master_portion,settlement_threshold,disbursed_at,paid_installments_count
active,{$member->email},10000,10000,0,0,2023-03-26,0
CSV;

    $path = writeLoanImportCsv($csv);
    $result = app(LoanImportService::class)->import($path);

    expect($result)->toMatchArray(['created' => 1, 'failed' => 0]);

    $loan = Loan::query()->where('member_id', $member->id)->latest('id')->firstOrFail();

    expect($loan->status)->toBe('completed')
        ->and((float) $loan->member_portion)->toBe(10000.0)
        ->and((float) $loan->master_portion)->toBe(0.0)
        ->and($loan->installments()->count())->toBe(0)
        ->and($loan->installments_count)->toBe(0);
});

test('loan import derives installment count from split repayment formula when portions are omitted', function () {
    $tierNumber = max(1, (int) LoanTier::withTrashed()->max('tier_number') + 1);

    $tier = LoanTier::create([
        'tier_number' => $tierNumber,
        'label' => 'Legacy 72K tier',
        'min_amount' => 61_000,
        'max_amount' => 90_000,
        'min_monthly_installment' => 2000,
        'is_active' => true,
    ]);

    FundTier::create([
        'tier_number' => min(254, $tierNumber + 1),
        'label' => 'Pool legacy 72K',
        'loan_tier_id' => $tier->id,
        'percentage' => 25,
        'is_active' => true,
    ]);

    $member = createLoanImportMember($this->accounting, 'legacy-72k-installments@example.test', 0);

    $csv = <<<CSV
loan_status,member_email,amount_approved,loan_tier_number,disbursed_at,paid_installments_count
active,{$member->email},72000,{$tierNumber},2024-09-29,0
CSV;

    $path = writeLoanImportCsv($csv);
    $result = app(LoanImportService::class)->import($path);

    expect($result)->toMatchArray(['created' => 1, 'failed' => 0]);

    $loan = Loan::query()->where('member_id', $member->id)->latest('id')->firstOrFail();

    expect($loan->installments()->count())->toBe(24)
        ->and($loan->installments_count)->toBe(24)
        ->and((float) $loan->member_portion)->toBe(72000.0)
        ->and((float) $loan->master_portion)->toBe(0.0);
});

test('loan import mirrors explicit master portion through member fund not master-only debit', function () {
    $member = createLoanImportMember($this->accounting, 'split-portions-mirror@example.test', 25_000);
    $masterFundBefore = (float) Account::masterFund()->balance;

    $csv = <<<CSV
loan_status,member_email,amount_approved,member_portion,master_portion,disbursed_at,paid_installments_count
active,{$member->email},10000,4000,6000,2024-06-01,0
CSV;

    $path = writeLoanImportCsv($csv);
    $result = app(LoanImportService::class)->import($path);

    expect($result)->toMatchArray(['created' => 1, 'failed' => 0]);

    $member->refresh()->load('fundAccount');
    $masterFund = Account::masterFund();

    expect((float) $member->fundAccount->balance)->toBe(15000.0)
        ->and((float) $masterFund->fresh()->balance)->toBe($masterFundBefore - 10000.0)
        ->and(
            Transaction::query()
                ->where('account_id', $masterFund->id)
                ->where('reference_type', Loan::class)
                ->where('description', 'like', '%'.__('(master fund share)').'%')
                ->where('type', 'debit')
                ->exists()
        )->toBeFalse();
});

test('loan import cash-out succeeds for a second loan when the member already has an active loan with emi reserve', function () {
    $member = createLoanImportMember($this->accounting, 'second-loan-cashout@example.test', 50_000);

    $firstCsv = <<<CSV
loan_status,member_email,amount_approved,member_portion,master_portion,disbursed_at,paid_installments_count
active,{$member->email},10000,5000,5000,2020-01-01,0
CSV;

    $secondCsv = <<<CSV
loan_status,member_email,amount_approved,member_portion,master_portion,disbursed_at,paid_installments_count
active,{$member->email},8000,4000,4000,2022-01-01,0
CSV;

    expect(app(LoanImportService::class)->import(writeLoanImportCsv($firstCsv)))->toMatchArray(['created' => 1, 'failed' => 0]);

    $firstLoan = Loan::query()->where('member_id', $member->id)->orderBy('id')->firstOrFail();
    expect($firstLoan->installments()->where('status', 'pending')->count())->toBeGreaterThan(0)
        ->and(app(MemberCashOutService::class)->reservedForNextEmi($member->fresh()))->toBeGreaterThan(0.0);

    $result = app(LoanImportService::class)->import(writeLoanImportCsv($secondCsv));

    expect($result)->toMatchArray(['created' => 1, 'failed' => 0])
        ->and(CashOutRequest::query()->where('member_id', $member->id)->count())->toBe(2)
        ->and(CashOutRequest::query()->where('member_id', $member->id)->where('status', 'accepted')->count())->toBe(2)
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(0.0);
});

test('loan import resolves borrower by member_name and assigns guarantor', function () {
    $borrower = createLoanImportMember($this->accounting, 'borrower-by-name@example.test');
    $borrower->update(['name' => 'Legacy Borrower By Name']);

    $guarantor = createLoanImportMember($this->accounting, 'guarantor-by-number@example.test');
    $guarantor->update([
        'name' => 'Legacy Guarantor Person',
        'member_number' => 'GUA-9001',
    ]);

    $csv = <<<'CSV'
loan_status,member_name,amount_approved,member_portion,master_portion,disbursed_at,guarantor_member_number,guarantor_name
active,Legacy Borrower By Name,5000,2500,2500,2024-07-01,GUA-9001,Legacy Guarantor Person
CSV;

    $path = writeLoanImportCsv($csv);
    $result = app(LoanImportService::class)->import($path);

    expect($result)->toMatchArray(['created' => 1, 'failed' => 0]);

    $loan = Loan::query()->where('member_id', $borrower->id)->firstOrFail();

    expect($loan->guarantor_member_id)->toBe($guarantor->id);
});

test('loan import rejects borrower and guarantor being the same member', function () {
    $member = createLoanImportMember($this->accounting, 'self-guarantor@example.test');
    $member->update(['name' => 'Self Guarantor Member', 'member_number' => 'SELF-001']);

    $csv = <<<'CSV'
loan_status,member_number,amount_approved,member_portion,master_portion,disbursed_at,guarantor_member_number
active,SELF-001,5000,2500,2500,2024-07-01,SELF-001
CSV;

    $path = writeLoanImportCsv($csv);
    $result = app(LoanImportService::class)->import($path);

    expect($result['created'])->toBe(0)
        ->and($result['failed'])->toBe(1)
        ->and($result['errors'][0])->toContain(__('Guarantor cannot be the same member as the borrower.'));
});

test('legacy migration sample loan import assigns guarantors when members exist', function () {
    $membersPath = storage_path('app/legacy-migration-loan-guarantor-members.csv');
    $loansPath = storage_path('app/legacy-migration-loan-guarantor-loans.csv');

    AssociativeCsv::write(
        $membersPath,
        LegacyMigrationSampleCsv::memberHeaders(),
        LegacyMigrationSampleCsv::memberRows(),
    );
    AssociativeCsv::write(
        $loansPath,
        LegacyMigrationSampleCsv::loanHeaders(),
        LegacyMigrationSampleCsv::loanRows(),
    );

    app(MemberImportService::class)->import($membersPath, 'password123', '2025-12-31');
    app(LoanImportService::class)->import($loansPath);

    $omar = Member::query()->where('member_number', 'MEM-1003')->firstOrFail();
    $fatimah = Member::query()->where('member_number', 'MEM-1002')->firstOrFail();
    Member::query()->where('member_number', 'MEM-1001')->firstOrFail();

    $omarLoan = Loan::query()->where('member_id', $omar->id)->where('status', 'active')->firstOrFail();
    $completedLoan = Loan::query()->where('member_id', Member::query()->where('member_number', 'MEM-1001')->value('id'))->where('status', 'completed')->firstOrFail();

    expect($omarLoan->guarantor_member_id)->toBe($fatimah->id)
        ->and($completedLoan->guarantor_member_id)->toBe($fatimah->id);

    @unlink($membersPath);
    @unlink($loansPath);
});

test('loan export streams csv with legacy-compatible headers', function () {
    $member = createLoanImportMember($this->accounting, 'export@example.test');

    Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $this->loanTier->id,
        'fund_tier_id' => $this->fundTier->id,
        'amount' => 8000,
        'amount_requested' => 8000,
        'amount_approved' => 8000,
        'interest_rate' => 10,
        'term_months' => 10,
        'monthly_repayment' => 0,
        'total_repaid' => 0,
        'member_portion' => 3000,
        'master_portion' => 5000,
        'purpose' => 'Export test',
        'installments_count' => 10,
        'status' => 'active',
        'applied_at' => now()->subMonths(2),
        'approved_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonth(),
    ]);

    $response = app(LoanExportService::class)->downloadCsv();

    ob_start();
    $response->sendContent();
    $content = ob_get_clean();

    expect($content)->toContain('loan_number')
        ->and($content)->toContain('member_number')
        ->and($content)->toContain($member->member_number)
        ->and($content)->toContain('8000.00');
});

test('portfolio tab table includes import and export header actions', function () {
    $component = Livewire::test(ListLoans::class)
        ->assertSet('activeTab', 'portfolio');

    $names = collect($component->instance()->getTable()->getHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($names)->toContain('importLoans', 'exportLoans', 'importRepayments', 'exportRepayments');
});

test('portfolio tab table can sort by outstanding balance', function () {
    $member = Member::create([
        'member_number' => 'OUT-SORT-1',
        'name' => 'Outstanding Sort Member',
        'email' => 'outstanding-sort@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->first()
        ?? LoanTier::create([
            'tier_number' => 88,
            'label' => 'Sort tier',
            'min_amount' => 1_000,
            'max_amount' => 100_000,
            'min_monthly_installment' => 500,
            'is_active' => true,
        ]);

    $lowerOutstanding = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 5_000,
        'amount_requested' => 5_000,
        'amount_approved' => 5_000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 500,
        'status' => 'active',
        'applied_at' => now()->subMonths(2),
        'approved_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);
    $higherOutstanding = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 15_000,
        'amount_requested' => 15_000,
        'amount_approved' => 15_000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 1_500,
        'status' => 'active',
        'applied_at' => now()->subMonth(),
        'approved_at' => now()->subMonth(),
        'disbursed_at' => now()->subMonth(),
    ]);

    LoanInstallment::create([
        'loan_id' => $lowerOutstanding->id,
        'installment_number' => 1,
        'amount' => 500,
        'due_date' => now()->addMonth(),
        'status' => 'pending',
    ]);
    LoanInstallment::create([
        'loan_id' => $higherOutstanding->id,
        'installment_number' => 1,
        'amount' => 1_500,
        'due_date' => now()->addMonth(),
        'status' => 'pending',
    ]);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListLoans::class)
        ->call('sortTable', 'outstanding')
        ->assertSuccessful();
});

test('emi collection tab table omits loan import export header actions', function () {
    $component = Livewire::test(ListLoans::class)
        ->set('activeTab', 'emi_collect');

    $names = collect($component->instance()->getTable()->getHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($names)->not->toContain('importLoans')
        ->and($names)->not->toContain('exportLoans')
        ->and($names)->not->toContain('importRepayments')
        ->and($names)->not->toContain('exportRepayments');
});

test('loan import sample download route returns csv', function () {
    $this->get('http://'.$this->domain.'/downloads/loan-import-sample')
        ->assertSuccessful()
        ->assertDownload('loans-import-sample-10.csv');
});

test('non-admin cannot import loans', function () {
    $user = User::create([
        'name' => 'Regular User',
        'email' => 'regular@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->actingAs($user, 'tenant');

    $path = writeLoanImportCsv("loan_status,member_email,amount_requested\npending,x@y.com,1000\n");

    app(LoanImportService::class)->import($path);
})->throws(AuthorizationException::class);

test('loan repayment import creates legacy row and posts fund repayment', function () {
    $member = createLoanImportMember($this->accounting, 'legacy-repayment@example.test', 20_000);

    $loan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $this->loanTier->id,
        'fund_tier_id' => $this->fundTier->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'member_portion' => 4000,
        'master_portion' => 6000,
        'purpose' => 'Repayment import test',
        'installments_count' => 10,
        'status' => 'active',
        'applied_at' => '2024-01-01',
        'approved_at' => '2024-01-01',
        'disbursed_at' => '2024-01-01',
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => '2025-04-01',
        'status' => 'pending',
    ]);
    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 1000,
        'due_date' => '2025-05-01',
        'status' => 'pending',
    ]);

    $csv = <<<CSV
loan_number,amount,paid_at,notes
{$loan->id},1500,2025-05-10 12:00:00,Historical bulk repayment
CSV;

    $result = app(LoanRepaymentImportService::class)->import(writeLoanImportCsv($csv));

    expect($result)->toMatchArray(['created' => 1, 'failed' => 0]);

    $repayment = LoanRepayment::query()->where('loan_id', $loan->id)->first();

    expect($repayment)->not->toBeNull()
        ->and((float) $repayment->amount)->toBe(1500.0)
        ->and($repayment->notes)->toBe('Historical bulk repayment');

    expect((float) $member->fresh()->fundAccount->balance)->toBe(21500.0)
        ->and($loan->installments()->where('status', 'paid')->count())->toBe(1)
        ->and($loan->installments()->where('status', 'pending')->count())->toBe(1);
});

test('loan repayment import marks installment paid without cash debit', function () {
    $member = createLoanImportMember($this->accounting, 'installment-repayment@example.test', 20_000);

    $loan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $this->loanTier->id,
        'fund_tier_id' => $this->fundTier->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'interest_rate' => 0,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'member_portion' => 3000,
        'master_portion' => 3000,
        'purpose' => 'Installment import test',
        'installments_count' => 6,
        'status' => 'active',
        'applied_at' => now()->subMonths(2),
        'approved_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonth(),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->subWeek()->toDateString(),
        'status' => 'pending',
    ]);

    $csv = <<<CSV
repayment_type,loan_number,installment_number,amount,paid_at
installment,{$loan->id},1,1000,2025-05-10 08:00:00
CSV;

    $result = app(LoanRepaymentImportService::class)->import(writeLoanImportCsv($csv));

    expect($result)->toMatchArray(['created' => 1, 'failed' => 0]);

    $installment = LoanInstallment::query()->where('loan_id', $loan->id)->firstOrFail();

    expect($installment->status)->toBe('paid')
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(0.0)
        ->and((float) $member->fresh()->fundAccount->balance)->toBe(21000.0);
});

test('loan repayment export streams legacy and installment rows', function () {
    $member = createLoanImportMember($this->accounting, 'repayment-export@example.test');

    $loan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $this->loanTier->id,
        'fund_tier_id' => $this->fundTier->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'interest_rate' => 0,
        'term_months' => 5,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'member_portion' => 2000,
        'master_portion' => 3000,
        'purpose' => 'Repayment export test',
        'installments_count' => 5,
        'status' => 'active',
        'applied_at' => now()->subMonths(2),
        'approved_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonth(),
    ]);

    $loan->repayments()->create([
        'amount' => 800,
        'paid_at' => now()->subDays(10),
        'notes' => 'Legacy export row',
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->subWeek()->toDateString(),
        'status' => 'paid',
        'paid_at' => now()->subDays(3),
    ]);

    ob_start();
    app(LoanRepaymentExportService::class)->downloadCsv()->sendContent();
    $content = ob_get_clean();

    expect($content)->toContain('repayment_type')
        ->and($content)->toContain('legacy')
        ->and($content)->toContain('installment')
        ->and($content)->toContain('Legacy export row');
});

test('loan repayment import sample download route returns csv', function () {
    $this->get('http://'.$this->domain.'/downloads/loan-repayment-import-sample')
        ->assertSuccessful()
        ->assertDownload('loan-repayments-import-sample.csv');
});

test('portfolio loans table can filter by loan tier and fund tier', function () {
    $memberA = createLoanImportMember($this->accounting, 'tier-filter-a@fund.test');
    $memberB = createLoanImportMember($this->accounting, 'tier-filter-b@fund.test');

    $otherLoanTier = LoanTier::create([
        'tier_number' => $this->loanTier->tier_number + 50,
        'label' => 'Filter tier B',
        'min_amount' => 1_000,
        'max_amount' => 50_000,
        'min_monthly_installment' => 500,
        'is_active' => true,
    ]);

    $otherFundTier = FundTier::create([
        'tier_number' => $this->fundTier->tier_number + 50,
        'label' => 'Filter pool B',
        'loan_tier_id' => $otherLoanTier->id,
        'percentage' => 25,
        'is_active' => true,
    ]);

    $matchingLoan = Loan::create([
        'member_id' => $memberA->id,
        'loan_tier_id' => $this->loanTier->id,
        'fund_tier_id' => $this->fundTier->id,
        'amount' => 5_000,
        'amount_requested' => 5_000,
        'amount_approved' => 5_000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 500,
        'status' => 'active',
        'applied_at' => now()->subMonth(),
        'approved_at' => now()->subMonth(),
    ]);

    $otherLoan = Loan::create([
        'member_id' => $memberB->id,
        'loan_tier_id' => $otherLoanTier->id,
        'fund_tier_id' => $otherFundTier->id,
        'amount' => 8_000,
        'amount_requested' => 8_000,
        'amount_approved' => 8_000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 800,
        'status' => 'active',
        'applied_at' => now()->subWeek(),
        'approved_at' => now()->subWeek(),
    ]);

    Livewire::test(ListLoans::class)
        ->assertSet('activeTab', 'portfolio')
        ->filterTable('loan_tier_id', $this->loanTier->id)
        ->assertCanSeeTableRecords([$matchingLoan])
        ->assertCanNotSeeTableRecords([$otherLoan])
        ->resetTableFilters()
        ->filterTable('fund_tier_id', $this->fundTier->id)
        ->assertCanSeeTableRecords([$matchingLoan])
        ->assertCanNotSeeTableRecords([$otherLoan]);
});
