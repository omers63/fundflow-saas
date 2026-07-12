<?php

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberDependentsInsightsService;
use App\Support\BusinessDaySettings;
use App\Support\Insights\InsightFormatter;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    app()->setLocale('en');
    Carbon::setTestNow(Carbon::parse('2026-06-15'));
    BusinessDaySettings::saveFromForm('2026-06-15');

    Member::query()->delete();
    User::query()->delete();
});

afterEach(function () {
    Carbon::setTestNow();
    BusinessDaySettings::saveFromForm(null);
});

function createParentWithDependents(array $dependentsConfig): array
{
    $parentUser = User::create([
        'name' => 'Parent User',
        'email' => 'parent-insights@dependents.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $parent = Member::create([
        'user_id' => $parentUser->id,
        'member_number' => 'MEM-P-INS',
        'name' => 'Parent User',
        'email' => 'parent-insights@dependents.test',
        'household_email' => 'parent-insights@dependents.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2026-06-01'),
        'contribution_arrears_cutoff_date' => Carbon::parse('2026-06-01'),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($parent);

    $dependents = collect();

    foreach ($dependentsConfig as $index => $config) {
        $dependent = Member::create([
            'parent_member_id' => $parent->id,
            'member_number' => 'MEM-D-INS'.($index + 1),
            'name' => $config['name'] ?? 'Dependent '.($index + 1),
            'email' => 'parent-insights@dependents.test',
            'household_email' => 'parent-insights@dependents.test',
            'monthly_contribution_amount' => $config['amount'] ?? 500,
            'exclude_from_household_contribution_funding' => $config['self_funded'] ?? false,
            'joined_at' => Carbon::parse('2026-06-01'),
            'contribution_arrears_cutoff_date' => Carbon::parse('2026-06-01'),
            'status' => 'active',
        ]);

        app(AccountingService::class)->createMemberAccounts($dependent);
        $dependents->push($dependent);
    }

    return [$parent, $dependents];
}

test('dependents insights returns empty for non parent', function () {
    $user = User::create([
        'name' => 'Solo',
        'email' => 'solo@dependents.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'MEM-SOLO',
        'name' => 'Solo',
        'email' => 'solo@dependents.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    expect(app(MemberDependentsInsightsService::class)->snapshot($member))->toBe([]);
});

test('dependents insights set contributions sums funded allocation amounts only', function () {
    [$parent] = createParentWithDependents([
        ['name' => 'Funded Child', 'amount' => 1000, 'self_funded' => false],
        ['name' => 'Self Funded Child', 'amount' => 1500, 'self_funded' => true],
    ]);

    $snapshot = app(MemberDependentsInsightsService::class)->snapshot($parent);

    expect($snapshot['kpis'][1]['label'])->toBe(__('Set contributions'))
        ->and($snapshot['kpis'][1]['value'])->toBe(InsightFormatter::compactAmount(1000))
        ->and($snapshot['kpis'][0]['sub'])->toBe(__(':funded funded · :self self-funded', [
            'funded' => 1,
            'self' => 1,
        ]))
        ->and($snapshot['open_period']['funded_dependents'])->toBe(1)
        ->and($snapshot['open_period']['total'])->toBe(1);
});

test('dependents insights cash to transfer shows emi shortfall separately from set contributions', function () {
    [$parent, $dependents] = createParentWithDependents([
        ['name' => 'Funded Borrower', 'amount' => 500, 'self_funded' => false],
    ]);

    $dependent = $dependents->first();

    $loan = Loan::create([
        'member_id' => $dependent->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 10,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-06-15'),
        'status' => 'pending',
    ]);

    expect($dependent->fresh()->isExemptFromContributions(6, 2026))->toBeTrue();

    $snapshot = app(MemberDependentsInsightsService::class)->snapshot($parent);

    expect($snapshot['kpis'][1]['label'])->toBe(__('Set contributions'))
        ->and($snapshot['kpis'][1]['value'])->toBe(InsightFormatter::compactAmount(500))
        ->and($snapshot['kpis'][2]['label'])->toBe(__('Cash to transfer'))
        ->and($snapshot['kpis'][2]['value'])->toBe(InsightFormatter::compactAmount(1000))
        ->and($snapshot['kpis'][3]['label'])->toBe(__('Contributions posted'))
        ->and($snapshot['kpis'][3]['value'])->toBe('—')
        ->and($snapshot['open_period']['total'])->toBe(0);
});

test('dependents insights open cycle counts only funded contribution obligations', function () {
    [$parent, $dependents] = createParentWithDependents([
        ['name' => 'Funded Child', 'amount' => 500, 'self_funded' => false],
        ['name' => 'Self Funded Child', 'amount' => 500, 'self_funded' => true],
    ]);

    $selfFunded = $dependents->last();

    Contribution::create([
        'member_id' => $selfFunded->id,
        'period' => Contribution::periodDate(6, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 500,
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    $funded = $dependents->first();

    Contribution::create([
        'member_id' => $funded->id,
        'period' => Contribution::periodDate(6, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 500,
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    $snapshot = app(MemberDependentsInsightsService::class)->snapshot($parent);

    expect($snapshot['kpis'][3]['label'])->toBe(__('Contributions posted'))
        ->and($snapshot['kpis'][3]['value'])->toBe(__('All posted'))
        ->and($snapshot['open_period']['posted'])->toBe(1)
        ->and($snapshot['open_period']['missing'])->toBe(0)
        ->and($snapshot['hero']['tone'])->toBe('success');
});

test('dependents insights hero reflects self-funded only household', function () {
    [$parent, $dependents] = createParentWithDependents([
        ['name' => 'Self Funded Child', 'amount' => 500, 'self_funded' => true],
    ]);

    Contribution::create([
        'member_id' => $dependents->first()->id,
        'period' => Contribution::periodDate(6, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 500,
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    $snapshot = app(MemberDependentsInsightsService::class)->snapshot($parent);

    expect($snapshot['hero']['title'])->toBe(__('Self-funded household'))
        ->and($snapshot['kpis'][1]['value'])->toBe('—')
        ->and($snapshot['kpis'][2]['value'])->toBe('—');
});
