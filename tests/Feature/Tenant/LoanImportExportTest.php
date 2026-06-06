<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Loans\Pages\ListLoans;
use App\Models\Central\Tenant;
use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Loans\LoanExportService;
use App\Services\Loans\LoanImportService;
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

    $member->refresh()->load('cashAccount', 'fundAccount');
    expect((float) $member->cashAccount->fresh()->balance)->toBe(10000.0);
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

    expect($names)->toContain('importLoans', 'exportLoans');
});

test('emi collection tab table omits loan import export header actions', function () {
    $component = Livewire::test(ListLoans::class)
        ->set('activeTab', 'emi_collect');

    $names = collect($component->instance()->getTable()->getHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($names)->not->toContain('importLoans')
        ->and($names)->not->toContain('exportLoans');
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
