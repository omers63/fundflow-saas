<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Loans\Pages\CreateLoan;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Support\ContributionCollectionStatus;
use App\Support\LoanFundExcessDisposition;
use App\Support\LoanFundingStrategy;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    App::setLocale('en');
    Filament::setCurrentPanel('tenant');

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 200_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 200_000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    FundTier::query()->update(['percentage' => 100]);

    $this->accounting = app(AccountingService::class);
});

function createEligibleMemberForAdminLoanCreate(AccountingService $accounting, float $fundBalance = 20_000): Member
{
    $member = Member::create([
        'member_number' => 'MEM-ADMIN-CREATE-'.uniqid(),
        'name' => 'Admin Create Loan Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $accounting->createMemberAccounts($member);
    $member->fundAccount()->update(['balance' => $fundBalance]);
    $member->cashAccount()->update(['balance' => 0]);

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $cursor = $member->joined_at->copy()->startOfMonth();

    while ($cursor->lte(Carbon::create($openYear, $openMonth, 1)->endOfMonth())) {
        $month = (int) $cursor->month;
        $year = (int) $cursor->year;

        if ((float) $member->monthly_contribution_amount > 0 && ! $member->isExemptFromContributions($month, $year)) {
            Contribution::create([
                'member_id' => $member->id,
                'period' => Contribution::periodDate($month, $year),
                'amount' => $member->monthly_contribution_amount,
                'amount_due' => $member->monthly_contribution_amount,
                'amount_collected' => $member->monthly_contribution_amount,
                'status' => 'posted',
                'collection_status' => ContributionCollectionStatus::COLLECTED,
                'posted_at' => $cursor->copy()->endOfMonth(),
                'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
                'is_late' => false,
            ]);
        }

        $cursor->addMonthNoOverflow();
    }

    return $member->fresh()->load(['fundAccount', 'cashAccount']);
}

test('admin create loan form uses wizard with funding strategy fields', function () {
    $contents = file_get_contents(resource_path('views/filament/tenant/resources/loans/pages/create-loan.blade.php'));
    $form = file_get_contents(app_path('Filament/Tenant/Resources/Loans/Schemas/LoanForm.php'));
    $fundingFields = file_get_contents(app_path('Filament/Support/LoanApplicationFundingFields.php'));

    expect($contents)->toContain('ff-tenant-create-loan')
        ->and($form)
        ->toContain('configureCreateWizard')
        ->toContain('LoanApplicationFundingFields')
        ->toContain('wire:click="create"')
        ->and($fundingFields)
        ->toContain('funding_strategy')
        ->toContain('excess_fund_disposition')
        ->toContain('funding_preview');
});

test('admin can create loan with split funding and excess cash-out preference', function () {
    $member = createEligibleMemberForAdminLoanCreate($this->accounting, fundBalance: 20_000);

    $admin = User::create([
        'name' => 'Loan Create Admin',
        'email' => 'loan-create-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(CreateLoan::class)
        ->fillForm([
            'member_id' => $member->id,
            'amount_requested' => 10_000,
            'funding_strategy' => LoanFundingStrategy::SPLIT_PERCENTAGE,
            'excess_fund_disposition' => LoanFundExcessDisposition::CASH_OUT,
            'grace_cycles' => 1,
            'purpose' => 'Education fees',
        ])
        ->call('create')
        ->assertHasNoFormErrors()
        ->assertNotified();

    $loan = Loan::query()->where('member_id', $member->id)->latest('id')->first();

    expect($loan)->not->toBeNull()
        ->and($loan->funding_strategy)->toBe(LoanFundingStrategy::SPLIT_PERCENTAGE)
        ->and($loan->cash_out_excess_fund)->toBeTrue()
        ->and((float) $loan->amount_requested)->toBe(10_000.0);
});
