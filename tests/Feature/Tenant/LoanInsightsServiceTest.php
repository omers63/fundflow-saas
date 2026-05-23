<?php

use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Services\LoanInsightsService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    $this->service = app(LoanInsightsService::class);

    Loan::query()->delete();
    FundTier::query()->delete();
    LoanTier::query()->delete();
    Member::query()->delete();

    Account::query()->delete();
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100_000, 'is_master' => true]);
});

test('portfolio snapshot returns pipeline counts and hero', function () {
    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Test Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);

    Loan::create([
        'member_id' => $member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'interest_rate' => 10,
        'term_months' => 12,
        'status' => 'pending',
        'applied_at' => now(),
    ]);

    $snapshot = $this->service->portfolioSnapshot();

    expect($snapshot)->toHaveKeys(['hero', 'kpis', 'pipeline', 'trend', 'currency'])
        ->and($snapshot['pipeline']['needs_decision'])->toBe(1)
        ->and($snapshot['hero']['tone'])->toBe('amber');
});

test('loan detail snapshot includes stepper and relation summaries', function () {
    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Test Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 8000,
        'amount_requested' => 8000,
        'amount_approved' => 8000,
        'interest_rate' => 10,
        'term_months' => 12,
        'status' => 'approved',
        'applied_at' => now()->subWeek(),
        'approved_at' => now(),
    ]);

    $snapshot = $this->service->loanDetailSnapshot($loan);

    expect($snapshot)->toHaveKeys(['steps', 'kpis', 'progress', 'relation_summaries'])
        ->and($snapshot['steps'])->not->toBeEmpty()
        ->and($snapshot['relation_summaries'])->toHaveCount(3);
});

test('fund tiers snapshot reports utilization', function () {
    LoanTier::query()->delete();

    $tier = LoanTier::create([
        'tier_number' => 99,
        'label' => 'Standard',
        'min_amount' => 1000,
        'max_amount' => 50_000,
        'min_monthly_installment' => 500,
        'is_active' => true,
    ]);

    FundTier::create([
        'tier_number' => 99,
        'label' => 'Pool A',
        'loan_tier_id' => $tier->id,
        'percentage' => 25,
        'is_active' => true,
    ]);

    $snapshot = $this->service->fundTiersSnapshot();

    expect($snapshot)->toHaveKeys(['utilization', 'breakdown', 'kpis'])
        ->and($snapshot['breakdown'])->toHaveCount(1);
});

test('member portfolio snapshot links to my loans routes on member panel', function () {
    Filament::setCurrentPanel('member');

    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Member Insights',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(6),
        'status' => 'active',
    ]);

    Loan::create([
        'member_id' => $member->id,
        'amount' => 3000,
        'amount_requested' => 3000,
        'interest_rate' => 10,
        'term_months' => 12,
        'status' => 'pending',
        'applied_at' => now(),
    ]);

    $snapshot = $this->service->memberPortfolioSnapshot($member->id);

    expect(Route::has('filament.member.resources.my-loans.index'))->toBeTrue()
        ->and($snapshot['kpis'])->not->toBeEmpty();

    foreach ($snapshot['kpis'] as $kpi) {
        expect($kpi['url'] ?? '')->toContain('/member/my-loans');
    }
});

test('loan detail snapshot on member panel uses my loan view urls', function () {
    Filament::setCurrentPanel('member');

    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Detail Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(6),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 4000,
        'amount_requested' => 4000,
        'amount_approved' => 4000,
        'interest_rate' => 10,
        'term_months' => 12,
        'status' => 'pending',
        'applied_at' => now(),
    ]);

    $snapshot = $this->service->loanDetailSnapshot($loan);

    expect($snapshot['view_url'])->toBe(MyLoanResource::getUrl('view', ['record' => $loan]))
        ->and($snapshot['hero']['cta_url'])->toContain('/member/my-loans');

    foreach (collect($snapshot['kpis'])->pluck('url')->unique()->all() as $url) {
        expect($url)->toContain('/member/my-loans');
    }
});
